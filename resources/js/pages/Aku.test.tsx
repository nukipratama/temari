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

    it('shows the connect button when Telegram is not connected', () => {
        const telegram = {
            connected: false,
            username: null,
            connect_url: 'https://t.me/temari_bot?start=tok',
            notify_post_run: true,
            notify_weekly_recap: true,
            notify_monthly_recap: true,
        };
        render(<Aku identity={identity} stats={stats} telegram={telegram} />);
        const link = screen.getByText('Hubungkan Telegram').closest('a');
        expect(link).toHaveAttribute('href', 'https://t.me/temari_bot?start=tok');
    });

    it('shows the preference toggles when Telegram is connected', () => {
        const telegram = {
            connected: true,
            username: 'ada_runs',
            connect_url: null,
            notify_post_run: true,
            notify_weekly_recap: false,
            notify_monthly_recap: true,
        };
        render(<Aku identity={identity} stats={stats} telegram={telegram} />);
        expect(screen.getByText(/Telegram aktif/)).toBeInTheDocument();
        expect(screen.getByRole('switch', { name: 'Cerita abis lari' })).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByRole('switch', { name: 'Rekap mingguan' })).toHaveAttribute('aria-checked', 'false');
        expect(screen.getByRole('switch', { name: 'Rekap bulanan' })).toHaveAttribute('aria-checked', 'true');
    });

    it('patches preferences when a toggle is flipped, carrying all current values', () => {
        vi.mocked(router.patch).mockReset();
        const telegram = {
            connected: true,
            username: null,
            connect_url: null,
            notify_post_run: true,
            notify_weekly_recap: false,
            notify_monthly_recap: true,
        };
        render(<Aku identity={identity} stats={stats} telegram={telegram} />);

        fireEvent.click(screen.getByRole('switch', { name: 'Rekap mingguan' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/profil/telegram',
            { notify_post_run: true, notify_weekly_recap: true, notify_monthly_recap: true },
            { preserveScroll: true },
        );
    });

    it('patches the monthly recap flag when its toggle is flipped', () => {
        vi.mocked(router.patch).mockReset();
        const telegram = {
            connected: true,
            username: null,
            connect_url: null,
            notify_post_run: true,
            notify_weekly_recap: true,
            notify_monthly_recap: true,
        };
        render(<Aku identity={identity} stats={stats} telegram={telegram} />);

        fireEvent.click(screen.getByRole('switch', { name: 'Rekap bulanan' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/profil/telegram',
            { notify_post_run: true, notify_weekly_recap: true, notify_monthly_recap: false },
            { preserveScroll: true },
        );
    });

    it('disconnects via DELETE when Putuskan is clicked', () => {
        vi.mocked(router.delete).mockReset();
        const telegram = {
            connected: true,
            username: null,
            connect_url: null,
            notify_post_run: true,
            notify_weekly_recap: true,
            notify_monthly_recap: true,
        };
        render(<Aku identity={identity} stats={stats} telegram={telegram} />);

        fireEvent.click(screen.getByText('Putuskan'));

        expect(router.delete).toHaveBeenCalledWith('/profil/telegram', { preserveScroll: true });
    });

    it('posts a test notification when "Kirim notifikasi tes" is clicked', () => {
        vi.mocked(router.post).mockReset();
        const telegram = {
            connected: true,
            username: null,
            connect_url: null,
            notify_post_run: true,
            notify_weekly_recap: true,
            notify_monthly_recap: true,
        };
        render(<Aku identity={identity} stats={stats} telegram={telegram} />);

        fireEvent.click(screen.getByText('Kirim notifikasi tes'));

        expect(router.post).toHaveBeenCalledWith('/profil/telegram/test', {}, { preserveScroll: true });
    });

    it('renders the AksesoriStrip when unlock catalog has entries', () => {
        const unlockCatalog = {
            'accessory.ikat_kepala_epik': {
                name: 'Ikat Kepala Luar Biasa',
                icon: 'mdi:bandana',
                description: '3 epic cards',
                criteria: 'Earn 3 epic',
            },
            'accessory.medal_emas': {
                name: 'Medali Emas',
                icon: 'mdi:medal',
                description: '5 PRs',
                criteria: 'Five PRs',
            },
        };
        const unlocks = [{ unlock_key: 'accessory.ikat_kepala_epik', unlocked_at: '2026-05-10' }];
        render(<Aku identity={identity} stats={stats} unlocks={unlocks} unlockCatalog={unlockCatalog} />);
        expect(screen.getByText(/Ikat Kepala Luar Biasa/)).toBeInTheDocument();
        expect(screen.getByText(/Medali Emas/)).toBeInTheDocument();
        expect(screen.getByText(/kebuka/)).toBeInTheDocument();
    });
});
