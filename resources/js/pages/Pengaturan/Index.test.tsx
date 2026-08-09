import { router } from '@inertiajs/react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import Pengaturan from './Index';

const connectedTelegram = {
    connected: true,
    username: 'ada_runs',
    connect_url: null,
};

const prefs = {
    notifications_enabled: false,
    telegram_enabled: true,
    push_enabled: true,
};

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser() },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('Pengaturan', () => {
    it('renders the settings sections', () => {
        render(<Pengaturan />);
        expect(screen.getByText('Notifications')).toBeInTheDocument();
        expect(screen.getByText('Telegram')).toBeInTheDocument();
        expect(screen.getByText('HR zones')).toBeInTheDocument();
        expect(screen.getByText('Delete account')).toBeInTheDocument();
    });

    // The page used to open with a bare <h1>Pengaturan</h1>, the only screen in
    // the app not using the editorial header every other page shares.
    it('opens with the editorial header rather than a bare title', () => {
        render(<Pengaturan />);
        expect(screen.getByText('Settings')).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'Set up Temari, your way.',
        );
    });

    // What gets sent and where it goes were three separate sections; they are
    // now two labelled groups inside one Notifikasi card.
    it('groups the notification settings by what and where', () => {
        render(<Pengaturan />);
        expect(screen.getByText('What gets sent')).toBeInTheDocument();
        expect(screen.getByText('Where it goes')).toBeInTheDocument();
    });

    // No back affordance anywhere: Pengaturan is one tap from the Aku tab and
    // from the avatar menu on every page, so a breadcrumb has no job here.
    it('has no back link', () => {
        render(<Pengaturan />);
        expect(
            screen.queryByRole('link', { name: /^Aku$/ }),
        ).not.toBeInTheDocument();
    });

    // The mute switches say "Kirim ke Telegram" nowhere near their real scope:
    // maintainer alerts and bot replies bypass them entirely. The group states
    // that out loud so the toggle is not writing a cheque the code will not
    // honour. See MaintainerAlerter.
    it('scopes the channel mutes to run notifications', () => {
        render(<Pengaturan />);
        expect(
            screen.getByText(/Controls your run notifications/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/system alerts still come through/),
        ).toBeInTheDocument();
    });

    it('tints the destructive row so it stops reading as routine', () => {
        render(<Pengaturan />);
        expect(screen.getByText('Delete account')).toHaveClass(
            'text-ember-deep',
        );
    });

    it('shows the Telegram connect link when not connected', () => {
        const telegram = {
            connected: false,
            username: null,
            connect_url: 'https://t.me/temari_bot?start=tok',
        };
        render(<Pengaturan telegram={telegram} />);
        expect(screen.getByRole('link', { name: /Telegram/ })).toHaveAttribute(
            'href',
            'https://t.me/temari_bot?start=tok',
        );
    });

    it('shows the channel-neutral master switch from notificationPrefs', () => {
        render(<Pengaturan notificationPrefs={prefs} />);
        expect(
            screen.getByRole('switch', { name: 'Keep me posted' }),
        ).toHaveAttribute('aria-checked', 'false');
    });

    // The streak nudge had no toggle of its own and silently rode along on
    // "Rekap mingguan"; the master switch names it so the coupling is visible.
    it('names the streak nudge among what the master switch sends', () => {
        render(<Pengaturan notificationPrefs={prefs} />);
        expect(
            screen.getByText(/nudge when your streak's about to end/),
        ).toBeInTheDocument();
    });

    it('patches the channel-neutral preferences when the master switch is flipped, carrying all current values', () => {
        vi.mocked(router.patch).mockReset();
        render(<Pengaturan notificationPrefs={prefs} />);

        fireEvent.click(screen.getByRole('switch', { name: 'Keep me posted' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/profil/notifikasi',
            {
                notifications_enabled: true,
                telegram_enabled: true,
                push_enabled: true,
            },
            { preserveScroll: true },
        );
    });

    it('posts a test notification when "Send test notification" is clicked', () => {
        vi.mocked(router.post).mockReset();
        render(<Pengaturan />);

        fireEvent.click(screen.getByText('Send test notification'));

        // The button routes through usePendingPost now, which adds its own
        // onStart/onSuccess/onFinish alongside the caller's options.
        expect(router.post).toHaveBeenCalledWith(
            '/profil/notifikasi/test',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    // Pressing it used to look like nothing happened, and pressing again either
    // sent a second time or hit the route throttle as a bare 429 the UI could
    // not explain.
    it('disables the test button with a countdown while the send is cooling', () => {
        vi.mocked(router.post).mockReset();
        render(<Pengaturan testCooldownSeconds={45} />);

        const button = screen.getByRole('button', {
            name: /Wait .* before sending a test notification/,
        });
        expect(button).toBeDisabled();

        fireEvent.click(button);
        expect(router.post).not.toHaveBeenCalled();
    });

    it('leaves the test button live when nothing is cooling', () => {
        render(<Pengaturan testCooldownSeconds={null} />);
        expect(
            screen.getByText('Send test notification').closest('button'),
        ).not.toBeDisabled();
    });

    it('opens the demo-blocked modal instead of patching when a demo user flips a toggle', () => {
        setMockPage({
            auth: { user: makeUser({ is_demo: true }) },
            flash: {},
            demoLoginEnabled: false,
        });
        vi.mocked(router.patch).mockReset();
        render(<Pengaturan notificationPrefs={prefs} />);

        const toggle = screen.getByRole('switch', { name: 'Keep me posted' });
        fireEvent.click(toggle);

        expect(router.patch).not.toHaveBeenCalled();
        expect(toggle).toHaveAttribute('aria-checked', 'false');
        expect(
            screen.getByText("Telegram's taking a break for now"),
        ).toBeInTheDocument();
    });

    it('disconnects via DELETE when Disconnect is clicked', () => {
        vi.mocked(router.delete).mockReset();
        render(<Pengaturan telegram={connectedTelegram} />);

        fireEvent.click(screen.getByText('Disconnect'));

        expect(router.delete).toHaveBeenCalledWith('/profil/telegram', {
            preserveScroll: true,
        });
    });

    it('opens a confirmation before deleting the account', () => {
        vi.mocked(router.delete).mockReset();
        render(<Pengaturan />);

        fireEvent.click(screen.getByText('Delete account'));
        expect(
            screen.getByText('Sure you want to delete your account?'),
        ).toBeInTheDocument();
        // Nothing is deleted until the user confirms.
        expect(router.delete).not.toHaveBeenCalled();
    });

    it('deletes the account via DELETE /akun when confirmed', () => {
        vi.mocked(router.delete).mockReset();
        render(<Pengaturan />);

        fireEvent.click(screen.getByText('Delete account'));
        fireEvent.click(
            screen.getByRole('button', { name: /Yes, delete my account/ }),
        );

        expect(router.delete).toHaveBeenCalledWith('/akun');
    });

    it('dismisses the confirmation without deleting', async () => {
        vi.mocked(router.delete).mockReset();
        render(<Pengaturan />);

        fireEvent.click(screen.getByText('Delete account'));
        expect(
            screen.getByText('Sure you want to delete your account?'),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Not now' }));
        await waitFor(() => {
            expect(
                screen.queryByText('Sure you want to delete your account?'),
            ).not.toBeInTheDocument();
        });
        expect(router.delete).not.toHaveBeenCalled();
    });
});
