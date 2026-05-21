import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Profile from './Profile';
import { setMockPage } from '@/test/setup';

const baseUser = { id: 1, name: 'Andi Runner', first_name: 'Andi', avatar_url: null as string | null };

const baseIdentity = {
    name: 'Andi Runner',
    avatar_url: null,
    first_run_at: '2026-01-15T07:00:00+07:00',
    member_since: '2025-08-10T00:00:00+07:00',
    strava_connected: true,
};

const baseStats = { total_runs: 12, total_km: 75.4, longest_run_km: 10.25 };

function setup({ user = baseUser, demoLoginEnabled = false } = {}) {
    setMockPage({ auth: { user }, flash: {}, demoLoginEnabled });
}

describe('Profile', () => {
    it('renders identity + hero stats with the three headline metrics', () => {
        setup();
        const { container } = render(<Profile identity={baseIdentity} stats={baseStats} />);
        const main = container.querySelector('main');
        expect(main).toHaveTextContent('Andi Runner');
        // Three hero numbers
        expect(main).toHaveTextContent('75.4'); // total km
        expect(main).toHaveTextContent('12'); // total runs
        expect(main).toHaveTextContent('10.25'); // longest run
    });

    it('shows the Strava-connected chip when strava_connected is true', () => {
        setup();
        render(<Profile identity={baseIdentity} stats={baseStats} />);
        expect(screen.getByText('Tersambung dengan Strava')).toBeInTheDocument();
    });

    it('omits the Strava chip when not connected', () => {
        setup();
        render(<Profile identity={{ ...baseIdentity, strava_connected: false }} stats={baseStats} />);
        expect(screen.queryByText('Tersambung dengan Strava')).not.toBeInTheDocument();
    });

    it('shows avatar image when avatar_url is set on identity', () => {
        setup();
        const { container } = render(
            <Profile identity={{ ...baseIdentity, avatar_url: 'https://example.com/a.jpg' }} stats={baseStats} />,
        );
        const img = container.querySelector('main img') as HTMLImageElement;
        expect(img.src).toBe('https://example.com/a.jpg');
        expect(img.alt).toBe('Andi Runner');
    });

    it('falls back to em-dash for longest run when zero', () => {
        setup();
        render(<Profile identity={baseIdentity} stats={{ ...baseStats, longest_run_km: 0 }} />);
        // Three hero tiles. Longest-run tile reads "—" with no suffix.
        expect(screen.getByText('—')).toBeInTheDocument();
    });

    it('renders a "Rekor terbaru" section with up to 3 PR tiles linking to activities', () => {
        setup();
        render(
            <Profile
                identity={baseIdentity}
                stats={baseStats}
                topPrs={[
                    { id: 1, category: '5km', value_sec: 1500, set_at: '2026-04-12T00:00:00+07:00', activity_id: 42, activity_name: 'Morning 5k' },
                    { id: 2, category: 'best_5min', value_sec: 280, set_at: '2026-03-01T00:00:00+07:00', activity_id: null, activity_name: null },
                ]}
            />,
        );
        expect(screen.getByRole('heading', { name: /Rekor terbaru/i })).toBeInTheDocument();
        expect(screen.getByText('5 KM')).toBeInTheDocument();
        expect(screen.getByText('Best 5 minutes')).toBeInTheDocument();
        // First tile is a link to its activity, second is a plain div.
        expect(screen.getByRole('link', { name: /5 KM/i })).toHaveAttribute('href', '/aktivitas/42');
    });

    it('hides "Rekor terbaru" when there are no PRs', () => {
        setup();
        render(<Profile identity={baseIdentity} stats={baseStats} topPrs={[]} />);
        expect(screen.queryByRole('heading', { name: /Rekor terbaru/i })).not.toBeInTheDocument();
    });

    it.each([
        { days: 3, expected: /Mulai berlari/i },
        { days: 28, expected: /4 minggu lalu/i },
        { days: 180, expected: /6 bulan lalu/i },
        { days: 800, expected: /2 tahun lalu/i },
    ])('renders running-since label for first_run_at $days days ago', ({ days, expected }) => {
        setup();
        const isoDaysAgo = new Date(Date.now() - days * 86_400_000).toISOString();
        render(<Profile identity={{ ...baseIdentity, first_run_at: isoDaysAgo }} stats={baseStats} />);
        expect(screen.getByText(expected)).toBeInTheDocument();
    });

    it('omits the running-since line when first_run_at and member_since are both null', () => {
        setup();
        const { container } = render(
            <Profile identity={{ ...baseIdentity, first_run_at: null, member_since: null }} stats={baseStats} />,
        );
        // Neither "Mulai berlari" nor "Berlari sejak" appears.
        expect(container).not.toHaveTextContent(/Mulai berlari|Berlari sejak/);
    });

    it('handles invalid date strings by skipping the running-since line', () => {
        setup();
        const { container } = render(
            <Profile identity={{ ...baseIdentity, first_run_at: 'not-a-date', member_since: null }} stats={baseStats} />,
        );
        expect(container).not.toHaveTextContent(/Berlari sejak|Mulai berlari/);
    });

    it('renders Koleksi section with unlocked + locked items', () => {
        setup();
        render(
            <Profile
                identity={baseIdentity}
                stats={baseStats}
                unlocks={[{ unlock_key: 'accessory.medal_gold', unlocked_at: '2026-04-01T00:00:00+07:00' }]}
                unlockCatalog={{
                    'accessory.medal_gold': {
                        name: 'Medali Emas',
                        icon: 'mdi:medal',
                        description: 'Diraih dari PR pertama.',
                        criteria: 'Unlocked saat PR pertama.',
                    },
                    'accessory.scarf_winter': {
                        name: 'Syal Winter',
                        icon: 'mdi:scarf',
                        description: 'Setia lari di musim dingin.',
                        criteria: 'Lari 5x di temp <15°C.',
                    },
                }}
            />,
        );
        expect(screen.getByText('Medali Emas')).toBeInTheDocument();
        expect(screen.getByText('Syal Winter')).toBeInTheDocument();
        // Locked tile shows criteria, unlocked shows description.
        expect(screen.getByText('Lari 5x di temp <15°C.')).toBeInTheDocument();
        expect(screen.getByText('Diraih dari PR pertama.')).toBeInTheDocument();
    });
});
