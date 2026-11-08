import { router } from '@inertiajs/react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import AccountActions from './AccountActions';

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser() },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('AccountActions', () => {
    // A button pair that turns sideways above 900px, not a SettingsRow list.
    it('draws the account actions as the prototype button pair', () => {
        render(<AccountActions />);

        const logOut = screen.getByRole('button', { name: /log out/ });
        expect(logOut).toHaveClass('w-full');
        expect(logOut).toHaveClass('min-[900px]:w-auto');
        expect(logOut.parentElement).toHaveClass('min-[900px]:flex-row');
    });

    it('posts to /logout when the Log out row is clicked', async () => {
        vi.mocked(router.post).mockReset();
        render(<AccountActions />);

        fireEvent.click(screen.getByText('log out'));

        await waitFor(() =>
            expect(router.post).toHaveBeenCalledWith('/logout', {}),
        );
    });

    it('tints the destructive row so it stops reading as routine', () => {
        render(<AccountActions />);
        expect(screen.getByText('delete account')).toHaveClass(
            'text-ember-ink',
        );
    });

    it('hides the delete-account control from the demo account, which the server refuses to delete', () => {
        setMockPage({
            auth: { user: makeUser({ is_demo: true }) },
            flash: {},
            demoLoginEnabled: false,
        });
        render(<AccountActions />);

        expect(screen.queryByText('delete account')).not.toBeInTheDocument();
        expect(screen.getByText('log out')).toBeInTheDocument();
    });

    it('opens a confirmation before deleting the account', async () => {
        vi.mocked(router.delete).mockReset();
        render(<AccountActions />);

        fireEvent.click(screen.getByText('delete account'));
        expect(
            await screen.findByText('sure you want to delete your account?'),
        ).toBeInTheDocument();
        expect(
            document.querySelector('[role="dialog"] svg[data-mascot]'),
        ).toHaveAttribute('data-mascot', 'concerned');
        expect(
            screen.getByRole('link', { name: "what's kept afterwards" }),
        ).toHaveAttribute('href', '/privacy');
        // Nothing is deleted until the user confirms.
        expect(router.delete).not.toHaveBeenCalled();
    });

    it('deletes the account via DELETE /account when confirmed', async () => {
        vi.mocked(router.delete).mockReset();
        render(<AccountActions />);

        fireEvent.click(screen.getByText('delete account'));
        fireEvent.click(
            await screen.findByRole('button', {
                name: /yes, delete my account/,
            }),
        );

        expect(router.delete).toHaveBeenCalledWith('/account');
    });

    it('dismisses the confirmation without deleting', async () => {
        vi.mocked(router.delete).mockReset();
        render(<AccountActions />);

        fireEvent.click(screen.getByText('delete account'));
        expect(
            await screen.findByText('sure you want to delete your account?'),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'not now' }));
        await waitFor(() => {
            expect(
                screen.queryByText('sure you want to delete your account?'),
            ).not.toBeInTheDocument();
        });
        expect(router.delete).not.toHaveBeenCalled();
    });
});
