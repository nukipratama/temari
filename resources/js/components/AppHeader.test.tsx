import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import * as Inertia from '@inertiajs/react';
import AppHeader from './AppHeader';
import { setMockPage } from '@/test/setup';

describe('AppHeader', () => {
    it('renders brand + nav links when user is anonymous', () => {
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: false });
        render(<AppHeader />);
        expect(screen.getByText('TemanLari')).toBeInTheDocument();
        expect(screen.getByText('Beranda')).toBeInTheDocument();
        expect(screen.getByText('Aktivitas')).toBeInTheDocument();
        expect(screen.getByText('Kartu')).toBeInTheDocument();
        expect(screen.getByText('Catatan')).toBeInTheDocument();
    });

    it('renders user name + avatar fallback when no avatar_url', () => {
        setMockPage(
            {
                auth: { user: { id: 1, name: 'Ada Lovelace', first_name: 'Ada', avatar_url: null } },
                flash: {},
                demoLoginEnabled: false,
            },
            '/dashboard',
        );
        render(<AppHeader />);
        expect(screen.getByText('Ada Lovelace')).toBeInTheDocument();
        expect(screen.getByText('A')).toBeInTheDocument(); // avatar initial
    });

    it('renders user avatar image when avatar_url is set', () => {
        setMockPage(
            {
                auth: {
                    user: {
                        id: 1,
                        name: 'Ada',
                        first_name: 'Ada',
                        avatar_url: 'https://example.test/a.png',
                    },
                },
                flash: {},
                demoLoginEnabled: false,
            },
            '/dashboard',
        );
        render(<AppHeader />);
        const img = screen.getByRole('presentation', { hidden: true });
        expect(img.getAttribute('src')).toBe('https://example.test/a.png');
    });

    it('marks the active nav link based on the current URL', () => {
        setMockPage(
            {
                auth: { user: { id: 1, name: 'A', first_name: 'A', avatar_url: null } },
                flash: {},
                demoLoginEnabled: false,
            },
            '/runs',
        );
        render(<AppHeader />);
        const link = screen.getByText('Aktivitas').closest('a');
        expect(link).toHaveClass(/text-brand-700/);
    });

    it('marks active when URL is a subpath of the link', () => {
        setMockPage(
            {
                auth: { user: { id: 1, name: 'A', first_name: 'A', avatar_url: null } },
                flash: {},
                demoLoginEnabled: false,
            },
            '/runs/42',
        );
        render(<AppHeader />);
        const link = screen.getByText('Aktivitas').closest('a');
        expect(link).toHaveClass(/text-brand-700/);
    });

    it('logs out via router.post on click', async () => {
        setMockPage(
            {
                auth: { user: { id: 1, name: 'A', first_name: 'A', avatar_url: null } },
                flash: {},
                demoLoginEnabled: false,
            },
            '/dashboard',
        );
        const postSpy = vi.spyOn(Inertia.router, 'post');
        render(<AppHeader />);
        await userEvent.setup().click(screen.getByText('Keluar'));
        expect(postSpy).toHaveBeenCalledWith('/logout');
    });
});
