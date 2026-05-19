import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Profile from './Profile';
import { setMockPage } from '@/test/setup';

const baseUser = { id: 1, name: 'Andi Runner', first_name: 'Andi', avatar_url: null as string | null };
const baseStats = { total_runs: 12, total_km: 75.4, member_since: '2025-08-10T00:00:00+07:00' };
const baseStrava = { athlete_id: 999, scopes: 'read,activity:read', token_expires_at: '2026-01-01T00:00:00+07:00' };

function setup({ user = baseUser, demoLoginEnabled = false } = {}) {
    setMockPage({ auth: { user }, flash: {}, demoLoginEnabled });
}

describe('Profile', () => {
    it('renders identitas + strava + stats sections with computed values', () => {
        setup();
        const { container } = render(<Profile stats={baseStats} strava={baseStrava} />);
        // user.name appears in Sidebar UserChip too; scope to the page <main>
        const main = container.querySelector('main');
        expect(main).toHaveTextContent('Andi Runner');
        expect(main).toHaveTextContent('999');
        expect(main).toHaveTextContent('read,activity:read');
        expect(main).toHaveTextContent('12');
        expect(main).toHaveTextContent('75.4');
    });

    it('omits strava section when connection is null', () => {
        setup();
        render(<Profile stats={baseStats} strava={null} />);
        // Strava section heading uses the `h2.uppercase` style — its
        // absence means the section didn't render. The word "Strava"
        // still appears in subtitle prose, so query the heading directly.
        expect(screen.queryByRole('heading', { name: /^Strava$/i })).not.toBeInTheDocument();
    });

    it('shows avatar image when user has avatar_url', () => {
        setup({ user: { ...baseUser, avatar_url: 'https://example.com/a.jpg' } });
        const { container } = render(<Profile stats={baseStats} strava={null} />);
        const img = container.querySelector('main img') as HTMLImageElement;
        expect(img.src).toBe('https://example.com/a.jpg');
        expect(img.alt).toBe('Andi Runner');
    });

    it('renders em-dash for token expiry + member-since when null', () => {
        setup();
        render(
            <Profile
                stats={{ ...baseStats, member_since: null }}
                strava={{ ...baseStrava, token_expires_at: null }}
            />,
        );
        // both '—' values present
        const dashes = screen.getAllByText('—');
        expect(dashes.length).toBeGreaterThanOrEqual(2);
    });

    it('renders Koleksi section with unlocked + locked items', () => {
        setup();
        render(
            <Profile
                stats={baseStats}
                strava={null}
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
        // Both names appear in the catalog grid
        expect(screen.getByText('Medali Emas')).toBeInTheDocument();
        expect(screen.getByText('Syal Winter')).toBeInTheDocument();
        // Locked item shows criteria; unlocked shows description
        expect(screen.getByText('Lari 5x di temp <15°C.')).toBeInTheDocument();
        expect(screen.getByText('Diraih dari PR pertama.')).toBeInTheDocument();
    });
});
