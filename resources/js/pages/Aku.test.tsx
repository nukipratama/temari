import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import Aku from './Aku';
import { makeUser, setMockPage } from '@/test/setup';

const identity = {
    name: 'Ada Lovelace',
    avatar_url: null,
    first_run_at: '2024-08-12',
    member_since: '2024-08-12',
    strava_connected: true,
};

const stats = { total_runs: 63, total_km: 544.1, longest_run_km: 17.99 };

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser() },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('Aku', () => {
    it('renders the editorial greeting with the first name', () => {
        render(<Aku identity={identity} stats={stats} />);
        expect(screen.getByText(/berlari sejak/i)).toBeInTheDocument();
        expect(screen.getByText('Ada Runner,')).toBeInTheDocument();
    });

    it('falls back to "Aku," when no first name is available', () => {
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: false });
        const noFirstName = { ...identity, name: '' };
        render(<Aku identity={noFirstName} stats={stats} />);
        expect(screen.getByText('Aku,')).toBeInTheDocument();
    });

    it('renders the three stat tiles', () => {
        render(<Aku identity={identity} stats={stats} />);
        expect(screen.getByText('Total km')).toBeInTheDocument();
        expect(screen.getByText('Total lari')).toBeInTheDocument();
        expect(screen.getByText('Lari terjauh')).toBeInTheDocument();
    });

    it('renders the persona bar + summary when personaMix is provided', () => {
        const mix = [
            { mood: 'enteng' as const, count: 22, percent: 34.9 },
            { mood: 'adem' as const, count: 21, percent: 33.3 },
        ];
        const summary = {
            id: 1,
            status: 'done' as const,
            content: 'Larimu konsisten dan pintar menjaga ritme.',
            type: 'persona_summary' as const,
            subject_type: 'persona_summary_user',
            subject_id: 1,
            discriminator: null,
        };
        render(<Aku identity={identity} stats={stats} personaMix={mix} personaSummary={summary} />);
        expect(screen.getByText(/Persona/)).toBeInTheDocument();
        expect(screen.getByText(/konsisten dan pintar/)).toBeInTheDocument();
    });

    it('renders top PR cards + the Semua rekor link when topPrs is non-empty', () => {
        const topPrs = [
            { id: 10, category: '5km', value_sec: 1751, set_at: '2026-05-16', activity_id: 99, activity_name: 'Sub-30 5K' },
            { id: 11, category: 'best_20min', value_sec: 349, set_at: '2026-05-16', activity_id: null, activity_name: null },
        ];
        render(<Aku identity={identity} stats={stats} topPrs={topPrs} />);
        expect(screen.getByText(/Rekor terbaru/)).toBeInTheDocument();
        expect(screen.getByText(/Semua rekor/)).toBeInTheDocument();
    });

    it('renders the AksesoriStrip when unlock catalog has entries', () => {
        const unlockCatalog = {
            'accessory.headband_epik': {
                name: 'Headband Epik',
                icon: 'mdi:bandana',
                description: '3 epic cards',
                criteria: 'Earn 3 epic',
            },
            'accessory.medal_gold': {
                name: 'Medali Emas',
                icon: 'mdi:medal',
                description: '5 PRs',
                criteria: 'Five PRs',
            },
        };
        const unlocks = [{ unlock_key: 'accessory.headband_epik', unlocked_at: '2026-05-10' }];
        render(<Aku identity={identity} stats={stats} unlocks={unlocks} unlockCatalog={unlockCatalog} />);
        expect(screen.getByText(/Headband Epik/)).toBeInTheDocument();
        expect(screen.getByText(/Medali Emas/)).toBeInTheDocument();
        expect(screen.getByText(/kebuka/)).toBeInTheDocument();
    });

    it('shows the Strava connect CTA when not connected', () => {
        const disconnected = { ...identity, strava_connected: false };
        render(<Aku identity={disconnected} stats={stats} />);
        expect(screen.getByText('Sambungkan')).toBeInTheDocument();
        expect(screen.getByText(/belum nyambung/)).toBeInTheDocument();
    });

    it('posts to /strava/sync when connected and Sync sekarang is clicked', () => {
        vi.mocked(router.post).mockReset();
        render(<Aku identity={identity} stats={stats} />);
        fireEvent.click(screen.getByText('Sync sekarang'));
        expect(router.post).toHaveBeenCalledWith('/strava/sync', {}, { preserveScroll: true });
    });

    it('renders the mobile-only logout footer + posts to /logout on click', () => {
        vi.mocked(router.post).mockReset();
        render(<Aku identity={identity} stats={stats} />);
        const keluar = screen.getByText('Keluar');
        fireEvent.click(keluar);
        expect(router.post).toHaveBeenCalledWith('/logout');
    });
});
