import { router } from '@inertiajs/react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import SendNotificationButton from './SendNotificationButton';

describe('SendNotificationButton', () => {
    it('posts to the given url when clicked', () => {
        vi.mocked(router.post).mockReset();
        render(<SendNotificationButton url="/aktivitas/99/kirim" />);
        fireEvent.click(screen.getByText('Send notification'));
        expect(router.post).toHaveBeenCalledWith(
            '/aktivitas/99/kirim',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('opens the demo-blocked modal instead of posting for a demo user', () => {
        setMockPage({ auth: { user: makeUser({ is_demo: true }) } });
        vi.mocked(router.post).mockReset();
        render(<SendNotificationButton url="/aktivitas/99/kirim" />);
        fireEvent.click(screen.getByText('Send notification'));
        expect(router.post).not.toHaveBeenCalledWith(
            '/aktivitas/99/kirim',
            expect.anything(),
            expect.anything(),
        );
        expect(
            screen.getByText("Telegram's taking a break for now"),
        ).toBeInTheDocument();
    });

    it('closes the demo-blocked modal when its Close button is pressed', async () => {
        setMockPage({ auth: { user: makeUser({ is_demo: true }) } });
        render(<SendNotificationButton url="/aktivitas/99/kirim" />);
        fireEvent.click(screen.getByText('Send notification'));
        fireEvent.click(screen.getByLabelText('Close'));
        await waitFor(() =>
            expect(
                screen.queryByText("Telegram's taking a break for now"),
            ).not.toBeInTheDocument(),
        );
    });

    it('opens the enable-notifications nudge (no post) when a real user taps the muted button', () => {
        setMockPage({ auth: { user: makeUser({ is_demo: false }) } });
        vi.mocked(router.post).mockReset();
        render(
            <SendNotificationButton
                url="/aktivitas/99/kirim"
                reachable={false}
            />,
        );
        fireEvent.click(screen.getByText('Send notification'));
        expect(router.post).not.toHaveBeenCalled();
        expect(
            screen.getByText('Turn on notifications first'),
        ).toBeInTheDocument();
    });

    it('closes the enable-notifications nudge when its Close button is pressed', async () => {
        setMockPage({ auth: { user: makeUser({ is_demo: false }) } });
        render(
            <SendNotificationButton
                url="/aktivitas/99/kirim"
                reachable={false}
            />,
        );
        fireEvent.click(screen.getByText('Send notification'));
        fireEvent.click(screen.getByLabelText('Close'));
        await waitFor(() =>
            expect(
                screen.queryByText('Turn on notifications first'),
            ).not.toBeInTheDocument(),
        );
    });

    it('opens the same enable nudge (not the demo modal) for a demo user tapping the muted button', () => {
        setMockPage({ auth: { user: makeUser({ is_demo: true }) } });
        render(
            <SendNotificationButton
                url="/aktivitas/99/kirim"
                reachable={false}
            />,
        );
        fireEvent.click(screen.getByText('Send notification'));
        expect(
            screen.getByText('Turn on notifications first'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText("Telegram's taking a break for now"),
        ).not.toBeInTheDocument();
    });

    it('disables the button and shows a spinner label while sending', () => {
        vi.mocked(router.post).mockImplementation((_url, _data, options) => {
            options?.onStart?.({} as never);
        });
        render(<SendNotificationButton url="/aktivitas/99/kirim" />);
        const button = screen.getByText('Send notification').closest('button')!;
        fireEvent.click(button);
        expect(button).toBeDisabled();
        expect(button).toHaveTextContent('Sending…');
    });

    it('disables the button and shows a countdown while on cooldown', () => {
        vi.mocked(router.post).mockReset();
        render(
            <SendNotificationButton
                url="/aktivitas/99/kirim"
                retryAfterSeconds={125}
            />,
        );
        const button = screen.getByLabelText(
            /wait.*before sending a notification/i,
        );
        expect(button).toBeDisabled();
        expect(button).toHaveTextContent('2:05');
        expect(button).not.toHaveTextContent('Send notification');
    });

    it('stays clickable when no cooldown is active', () => {
        vi.mocked(router.post).mockReset();
        render(
            <SendNotificationButton
                url="/aktivitas/99/kirim"
                retryAfterSeconds={null}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Send notification' }),
        ).not.toBeDisabled();
    });
});
