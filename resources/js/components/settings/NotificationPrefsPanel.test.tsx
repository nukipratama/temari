import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import NotificationPrefsPanel from './NotificationPrefsPanel';

const telegram = {
    connected: false,
    username: null,
    connect_url: null,
};

const prefs = {
    notifications_enabled: false,
    briefing_push_enabled: true,
    briefing_push_minute: null,
    telegram_enabled: true,
    push_enabled: true,
};

function renderPanel(
    overrides: Partial<typeof prefs> = {},
    testCooldownSeconds: number | null = null,
) {
    return render(
        <NotificationPrefsPanel
            prefs={{ ...prefs, ...overrides }}
            telegram={telegram}
            testCooldownSeconds={testCooldownSeconds}
        />,
    );
}

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser() },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('NotificationPrefsPanel', () => {
    // What gets sent and where it goes were three separate sections; they are
    // now two labelled groups inside one Notifications card.
    it('groups the notification settings by what and where', () => {
        renderPanel();
        expect(screen.getByText('What gets sent')).toBeInTheDocument();
        expect(screen.getByText('Where it goes')).toBeInTheDocument();
    });

    // The mute switches say "Send run notifications to Telegram" nowhere near
    // their real scope: maintainer alerts and bot replies bypass them entirely.
    // The group states that out loud so the toggle is not writing a cheque the
    // code will not honour. See MaintainerAlerter.
    it('scopes the channel mutes to run notifications', () => {
        renderPanel();
        expect(
            screen.getByText(/controls your run notifications/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/system alerts still come through/),
        ).toBeInTheDocument();
    });

    it('says how to keep only one channel with the mutes', () => {
        renderPanel();
        expect(
            screen.getByText(/to keep just one, mute the other/),
        ).toBeInTheDocument();
    });

    // The streak nudge had no toggle of its own and silently rode along on
    // "weekly recap"; the master switch names it so the coupling is visible.
    it('names the streak nudge among what the master switch sends', () => {
        renderPanel();
        expect(
            screen.getByText(/nudge when your streak's about to end/),
        ).toBeInTheDocument();
    });

    it('names the race-outcome ask among what the master switch sends', () => {
        renderPanel();
        expect(
            screen.getByText(/a check-in on how it went/),
        ).toBeInTheDocument();
    });

    it('names the fitness-improved and time-trial asks among what the master switch sends', () => {
        renderPanel();
        expect(
            screen.getByText(
                /word when your race time gets quicker, a check on whether a hard run was your all-out trial/,
            ),
        ).toBeInTheDocument();
    });

    it('names the hot-easy-run warning among what the master switch sends', () => {
        renderPanel();
        expect(
            screen.getByText(
                /a warning when easy runs running hot will cost next week a quality session/,
            ),
        ).toBeInTheDocument();
    });

    it('patches the channel-neutral preferences when the master switch is flipped, carrying all current values', () => {
        vi.mocked(router.patch).mockReset();
        renderPanel();

        fireEvent.click(screen.getByRole('switch', { name: 'keep me posted' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/profile/notifications',
            {
                notifications_enabled: true,
                briefing_push_enabled: true,
                briefing_push_minute: null,
                telegram_enabled: true,
                push_enabled: true,
            },
            { preserveScroll: true },
        );
    });

    it('greys out the briefing controls while the master switch is off', () => {
        renderPanel();

        expect(
            screen.getByRole('switch', { name: 'morning briefing' }),
        ).toBeDisabled();
        expect(
            screen.getByLabelText('send the morning briefing at'),
        ).toBeDisabled();
    });

    it('patches the briefing switch on its own, carrying all current values', () => {
        vi.mocked(router.patch).mockReset();
        renderPanel({ notifications_enabled: true });

        fireEvent.click(
            screen.getByRole('switch', { name: 'morning briefing' }),
        );

        expect(router.patch).toHaveBeenCalledWith(
            '/profile/notifications',
            expect.objectContaining({
                notifications_enabled: true,
                briefing_push_enabled: false,
            }),
            { preserveScroll: true },
        );
        expect(
            screen.getByLabelText('send the morning briefing at'),
        ).toBeDisabled();
    });

    it('saves a chosen send time as minutes once the field is left', () => {
        vi.mocked(router.patch).mockReset();
        renderPanel({ notifications_enabled: true });
        const field = screen.getByLabelText('send the morning briefing at');

        fireEvent.change(field, { target: { value: '05:30' } });
        expect(router.patch).not.toHaveBeenCalled();

        fireEvent.blur(field);
        expect(router.patch).toHaveBeenCalledWith(
            '/profile/notifications',
            expect.objectContaining({ briefing_push_minute: 330 }),
            { preserveScroll: true },
        );

        fireEvent.blur(field);
        expect(router.patch).toHaveBeenCalledTimes(1);
    });

    it('passes the cooldown on to the test button', () => {
        renderPanel({}, 45);

        expect(
            screen.getByRole('button', {
                name: /Wait .* before sending a test notification/,
            }),
        ).toBeDisabled();
    });

    it('opens the demo-blocked modal instead of patching when a demo user flips a toggle', async () => {
        setMockPage({
            auth: { user: makeUser({ is_demo: true }) },
            flash: {},
            demoLoginEnabled: false,
        });
        vi.mocked(router.patch).mockReset();
        renderPanel();

        const toggle = screen.getByRole('switch', { name: 'keep me posted' });
        fireEvent.click(toggle);

        expect(router.patch).not.toHaveBeenCalled();
        expect(toggle).toHaveAttribute('aria-checked', 'false');
        expect(
            await screen.findByText("Telegram's taking a break for now"),
        ).toBeInTheDocument();
    });
});
