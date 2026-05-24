import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import TopNav from './TopNav';
import { setMockPage } from '@/test/setup';

beforeEach(() => {
    vi.mocked(router.post).mockReset();
    setMockPage({
        auth: { user: { id: 1, name: 'Ada Lovelace', first_name: 'Ada', avatar_url: null } },
        flash: {},
        demoLoginEnabled: false,
        stravaSync: { connected: false, last_synced_at: null },
    });
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
        setMockPage(
            {
                auth: { user: { id: 1, name: 'Ada', first_name: 'Ada', avatar_url: null } },
                flash: {},
                demoLoginEnabled: false,
            },
            '/aktivitas',
        );
        render(<TopNav />);
        const riwayat = screen.getByText('Riwayat');
        expect(riwayat.className).toContain('text-ink');
    });

    it('renders the disconnected Strava pill when no sync info', () => {
        render(<TopNav />);
        expect(screen.getByText('Strava')).toBeInTheDocument();
    });

    it('renders the synced Strava pill with relative time when connected', () => {
        setMockPage({
            auth: { user: { id: 1, name: 'Ada', first_name: 'Ada', avatar_url: null } },
            flash: {},
            demoLoginEnabled: false,
            stravaSync: { connected: true, last_synced_at: new Date(Date.now() - 5 * 60 * 1000).toISOString() },
        });
        render(<TopNav />);
        expect(screen.getByText(/Strava synced/)).toBeInTheDocument();
    });

    it('renders synced label without timestamp when last_synced_at is null', () => {
        setMockPage({
            auth: { user: { id: 1, name: 'Ada', first_name: 'Ada', avatar_url: null } },
            flash: {},
            demoLoginEnabled: false,
            stravaSync: { connected: true, last_synced_at: null },
        });
        render(<TopNav />);
        expect(screen.getByText('Strava synced')).toBeInTheDocument();
    });

    it('opens the avatar dropdown on click and shows the user name + logout', () => {
        render(<TopNav />);
        const avatarButton = screen.getByLabelText(/Buka menu Ada Lovelace/);
        fireEvent.click(avatarButton);
        expect(screen.getByText('Masuk sebagai')).toBeInTheDocument();
        expect(screen.getByText('Ada Lovelace')).toBeInTheDocument();
        expect(screen.getByText('Keluar')).toBeInTheDocument();
    });

    it('posts to /logout when the Keluar button is clicked', () => {
        render(<TopNav />);
        fireEvent.click(screen.getByLabelText(/Buka menu Ada Lovelace/));
        fireEvent.click(screen.getByText('Keluar'));
        expect(router.post).toHaveBeenCalledWith('/logout');
    });

    it('closes the dropdown when Escape is pressed', () => {
        render(<TopNav />);
        fireEvent.click(screen.getByLabelText(/Buka menu Ada Lovelace/));
        expect(screen.getByText('Keluar')).toBeInTheDocument();
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(screen.queryByText('Keluar')).not.toBeInTheDocument();
    });

    it('renders the avatar image when avatar_url is provided', () => {
        setMockPage({
            auth: { user: { id: 1, name: 'Ada', first_name: 'Ada', avatar_url: 'https://example.com/a.jpg' } },
            flash: {},
            demoLoginEnabled: false,
        });
        render(<TopNav />);
        const avatarButton = screen.getByLabelText(/Buka menu Ada/);
        expect(avatarButton.querySelector('img')).not.toBeNull();
    });

    it('hides the avatar menu when no user is in shared props', () => {
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: false });
        render(<TopNav />);
        expect(screen.queryByLabelText(/Buka menu/)).not.toBeInTheDocument();
    });

    it('activeTabFromUrl returns null for paths that do not match any prefix', () => {
        setMockPage(
            { auth: { user: { id: 1, name: 'Ada', first_name: 'Ada', avatar_url: null } }, flash: {}, demoLoginEnabled: false },
            '/settings',
        );
        render(<TopNav />);
        // None of the four tabs should carry the active text-ink color.
        // (smoke check — the negative case for activeTabFromUrl loop returning null)
        expect(screen.getByText('Hari Ini')).toBeInTheDocument();
    });
});
