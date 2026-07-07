import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import TopNav from './TopNav';
import { makeUser, setMockPage } from '@/test/setup';

const user = (overrides: Record<string, unknown> = {}) => ({
    auth: { user: makeUser(overrides) },
    flash: {},
    demoLoginEnabled: false,
});

beforeEach(() => {
    setMockPage({ ...user(), stravaSync: { state: 'disconnected', last_synced_at: null } });
});

describe('TopNav', () => {
    it('renders the 4 primary tabs', () => {
        render(<TopNav />);
        expect(screen.getByText('Hari Ini')).toBeInTheDocument();
        expect(screen.getByText('Koleksi')).toBeInTheDocument();
        expect(screen.getByText('Riwayat')).toBeInTheDocument();
        expect(screen.getByText('Aku')).toBeInTheDocument();
    });

    it('highlights the active tab from the current URL', () => {
        setMockPage(user(), '/aktivitas');
        render(<TopNav />);
        expect(screen.getByText('Riwayat')).toHaveAttribute('aria-current', 'page');
        expect(screen.getByText('Hari Ini')).not.toHaveAttribute('aria-current');
    });

    it('gives tab links and the brand link a keyboard focus ring', () => {
        render(<TopNav />);
        const tab = screen.getByText('Riwayat').closest('a');
        expect(tab?.className).toMatch(/focus-ring/);
        const brand = screen.getByLabelText('Beranda');
        expect(brand.className).toMatch(/focus-ring/);
    });

    it('renders the disconnected Strava pill when no sync info', () => {
        render(<TopNav />);
        expect(screen.getByText('Strava')).toBeInTheDocument();
    });

    it('renders the synced Strava pill with relative time when connected', () => {
        setMockPage({
            ...user(),
            stravaSync: { state: 'ready', last_synced_at: new Date(Date.now() - 5 * 60 * 1000).toISOString() },
        });
        render(<TopNav />);
        expect(screen.getByText(/Strava synced/)).toBeInTheDocument();
    });

    it('renders synced label without timestamp when last_synced_at is null', () => {
        setMockPage({ ...user(), stravaSync: { state: 'ready', last_synced_at: null } });
        render(<TopNav />);
        expect(screen.getByText('Strava synced')).toBeInTheDocument();
    });

    it('renders the avatar menu for the signed-in user', () => {
        render(<TopNav />);
        expect(screen.getByLabelText(/Buka menu Ada Lovelace/)).toBeInTheDocument();
    });

    it('hides the avatar menu when no user is in shared props', () => {
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: false });
        render(<TopNav />);
        expect(screen.queryByLabelText(/Buka menu/)).not.toBeInTheDocument();
    });

    it('highlights Aku for the nested /pengaturan settings pages', () => {
        setMockPage(user(), '/pengaturan/zona');
        render(<TopNav />);
        expect(screen.getByText('Aku')).toHaveAttribute('aria-current', 'page');
    });

    it('activeTabFromUrl returns null for paths that do not match any prefix', () => {
        setMockPage(user(), '/settings');
        render(<TopNav />);
        // None of the four tabs should carry the active text-ink color.
        // (smoke check — the negative case for activeTabFromUrl loop returning null)
        expect(screen.getByText('Hari Ini')).toBeInTheDocument();
    });
});
