import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Settings from './Settings';
import { setMockPage } from '@/test/setup';

describe('Settings', () => {
    it('renders the Akun section with a logout button', () => {
        setMockPage({
            auth: { user: { id: 1, name: 'A', first_name: 'A', avatar_url: null } },
            flash: {},
            demoLoginEnabled: false,
        });
        render(<Settings />);
        expect(screen.getByText('Akun')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Keluar/ })).toBeInTheDocument();
    });

    it('shows Demo Mode info chip when demoLoginEnabled is true', () => {
        setMockPage({
            auth: { user: { id: 1, name: 'A', first_name: 'A', avatar_url: null } },
            flash: {},
            demoLoginEnabled: true,
        });
        render(<Settings />);
        expect(screen.getByText('Demo Mode')).toBeInTheDocument();
    });

    it('hides Demo Mode chip when demoLoginEnabled is false', () => {
        setMockPage({
            auth: { user: { id: 1, name: 'A', first_name: 'A', avatar_url: null } },
            flash: {},
            demoLoginEnabled: false,
        });
        render(<Settings />);
        expect(screen.queryByText('Demo Mode')).not.toBeInTheDocument();
    });

    it('logout button calls router.post', async () => {
        const inertia = await import('@inertiajs/react');
        setMockPage({
            auth: { user: { id: 1, name: 'A', first_name: 'A', avatar_url: null } },
            flash: {},
            demoLoginEnabled: false,
        });
        render(<Settings />);
        fireEvent.click(screen.getByRole('button', { name: /Keluar/ }));
        expect(vi.mocked(inertia.router.post)).toHaveBeenCalledWith('/logout');
    });
});
