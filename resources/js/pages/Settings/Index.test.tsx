import { router } from '@inertiajs/react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import Settings from './Index';

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

describe('Settings', () => {
    it('renders the settings sections', () => {
        render(<Settings />);
        expect(screen.getByText('Notifications')).toBeInTheDocument();
        expect(screen.getByText('Telegram')).toBeInTheDocument();
        expect(screen.getByText('HR zones')).toBeInTheDocument();
        expect(screen.getByText('Delete account')).toBeInTheDocument();
    });

    it('expands the HR zones disclosure inline, without navigating', () => {
        render(<Settings />);
        expect(screen.queryByLabelText('Max HR')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /HR zones/ }));

        expect(screen.getByLabelText('Max HR')).toBeInTheDocument();
    });

    it('passes the server-supplied HR-zones profile into the disclosure', () => {
        render(
            <Settings
                hrZones={{
                    profile: {
                        max_hr: 200,
                        resting_hr: 48,
                        hr_zones: {
                            Z1: { lo: 122, hi: 143 },
                            Z2: { lo: 143, hi: 160 },
                            Z3: { lo: 160, hi: 175 },
                            Z4: { lo: 175, hi: 185 },
                            Z5: { lo: 185, hi: 999 },
                        },
                        optimal_cadence_spm: 172,
                    },
                    source: 'manual',
                    stravaSyncedLabel: null,
                    canSyncFromStrava: false,
                }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /HR zones/ }));
        expect(screen.getByLabelText('Max HR')).toHaveValue(200);
    });

    it('links out to the four legal pages', () => {
        render(<Settings />);

        expect(screen.getByText('The fine print')).toBeInTheDocument();
        for (const [label, href] of [
            ['Terms of use', '/terms'],
            ['Privacy policy', '/privacy'],
            ['How Temari uses AI', '/ai-use'],
            ['Training disclaimer', '/training-disclaimer'],
        ]) {
            expect(
                screen.getByRole('link', { name: new RegExp(label) }),
            ).toHaveAttribute('href', href);
        }
    });

    it('renders the data-use statement the server hands it', () => {
        render(
            <Settings
                dataUse={{
                    headline: 'Your data',
                    points: ['Inference only, never training.'],
                }}
            />,
        );
        expect(screen.getByText('Your data')).toBeInTheDocument();
        expect(
            screen.getByText('Inference only, never training.'),
        ).toBeInTheDocument();
    });

    // The page used to open with a bare <h1>Pengaturan</h1>, the only screen in
    // the app not using the editorial header every other page shares.
    it('opens with the editorial header rather than a bare title', () => {
        render(<Settings />);
        expect(screen.getAllByText('Settings').length).toBeGreaterThan(0);
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'set up temari, your way.',
        );
    });

    // What gets sent and where it goes were three separate sections; they are
    // now two labelled groups inside one Notifikasi card.
    it('groups the notification settings by what and where', () => {
        render(<Settings />);
        expect(screen.getByText('What gets sent')).toBeInTheDocument();
        expect(screen.getByText('Where it goes')).toBeInTheDocument();
    });

    // No breadcrumb-style back affordance in the page body: Settings is a
    // pushed screen and the shell topbar's back chevron owns the way out.
    it('has no breadcrumb-style back link', () => {
        const { container } = render(<Settings />);
        expect(
            container.querySelector('[data-icon="mdi:arrow-left"]'),
        ).toBeNull();
    });

    it('renders no in-page Me nav — the topbar chrome replaces it', () => {
        render(<Settings />);
        expect(
            screen.queryByRole('link', { name: 'Profile' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Accessories' }),
        ).not.toBeInTheDocument();
    });

    // The mute switches say "Send run notifications to Telegram" nowhere near
    // their real scope: maintainer alerts and bot replies bypass them entirely.
    // The group states that out loud so the toggle is not writing a cheque the
    // code will not honour. See MaintainerAlerter.
    it('scopes the channel mutes to run notifications', () => {
        render(<Settings />);
        expect(
            screen.getByText(/Controls your run notifications/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/system alerts still come through/),
        ).toBeInTheDocument();
    });

    it('posts to /logout when the Log out row is clicked', () => {
        vi.mocked(router.post).mockReset();
        render(<Settings />);

        fireEvent.click(screen.getByText('Log out'));

        expect(router.post).toHaveBeenCalledWith('/logout');
    });

    it('tints the destructive row so it stops reading as routine', () => {
        render(<Settings />);
        expect(screen.getByText('Delete account')).toHaveClass(
            'text-ember-ink',
        );
    });

    it('shows the Telegram connect link when not connected', () => {
        const telegram = {
            connected: false,
            username: null,
            connect_url: 'https://t.me/temari_bot?start=tok',
        };
        render(<Settings telegram={telegram} />);
        expect(screen.getByRole('link', { name: /Telegram/ })).toHaveAttribute(
            'href',
            'https://t.me/temari_bot?start=tok',
        );
    });

    it('shows the channel-neutral master switch from notificationPrefs', () => {
        render(<Settings notificationPrefs={prefs} />);
        expect(
            screen.getByRole('switch', { name: 'Keep me posted' }),
        ).toHaveAttribute('aria-checked', 'false');
    });

    // The streak nudge had no toggle of its own and silently rode along on
    // "Rekap mingguan"; the master switch names it so the coupling is visible.
    it('names the streak nudge among what the master switch sends', () => {
        render(<Settings notificationPrefs={prefs} />);
        expect(
            screen.getByText(/nudge when your streak's about to end/),
        ).toBeInTheDocument();
    });

    it('patches the channel-neutral preferences when the master switch is flipped, carrying all current values', () => {
        vi.mocked(router.patch).mockReset();
        render(<Settings notificationPrefs={prefs} />);

        fireEvent.click(screen.getByRole('switch', { name: 'Keep me posted' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/profile/notifications',
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
        render(<Settings />);

        fireEvent.click(screen.getByText('Send test notification'));

        // The button routes through usePendingPost now, which adds its own
        // onStart/onSuccess/onFinish alongside the caller's options.
        expect(router.post).toHaveBeenCalledWith(
            '/profile/notifications/test',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    // Pressing it used to look like nothing happened, and pressing again either
    // sent a second time or hit the route throttle as a bare 429 the UI could
    // not explain.
    it('disables the test button with a countdown while the send is cooling', () => {
        vi.mocked(router.post).mockReset();
        render(<Settings testCooldownSeconds={45} />);

        const button = screen.getByRole('button', {
            name: /Wait .* before sending a test notification/,
        });
        expect(button).toBeDisabled();

        fireEvent.click(button);
        expect(router.post).not.toHaveBeenCalled();
    });

    it('leaves the test button live when nothing is cooling', () => {
        render(<Settings testCooldownSeconds={null} />);
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
        render(<Settings notificationPrefs={prefs} />);

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
        render(<Settings telegram={connectedTelegram} />);

        fireEvent.click(screen.getByText('Disconnect'));

        expect(router.delete).toHaveBeenCalledWith('/profile/telegram', {
            preserveScroll: true,
        });
    });

    it('opens a confirmation before deleting the account', () => {
        vi.mocked(router.delete).mockReset();
        render(<Settings />);

        fireEvent.click(screen.getByText('Delete account'));
        expect(
            screen.getByText('Sure you want to delete your account?'),
        ).toBeInTheDocument();
        // Nothing is deleted until the user confirms.
        expect(router.delete).not.toHaveBeenCalled();
    });

    it('deletes the account via DELETE /account when confirmed', () => {
        vi.mocked(router.delete).mockReset();
        render(<Settings />);

        fireEvent.click(screen.getByText('Delete account'));
        fireEvent.click(
            screen.getByRole('button', { name: /Yes, delete my account/ }),
        );

        expect(router.delete).toHaveBeenCalledWith('/account');
    });

    it('dismisses the confirmation without deleting', async () => {
        vi.mocked(router.delete).mockReset();
        render(<Settings />);

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
