import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import TestSendButton from './TestSendButton';

const runNow = (run: () => void) => run();

describe('TestSendButton', () => {
    it('posts a test notification when "send test notification" is clicked', () => {
        vi.mocked(router.post).mockReset();
        render(<TestSendButton cooldownSeconds={null} guard={runNow} />);

        fireEvent.click(screen.getByText('send test notification'));

        // The button routes through usePendingPost now, which adds its own
        // onStart/onSuccess/onFinish alongside the caller's options.
        expect(router.post).toHaveBeenCalledWith(
            '/profile/notifications/test',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    // Pressing it must not look like nothing happened, nor send twice or hit
    // the route throttle as a bare 429 the UI cannot explain.
    it('disables the test button with a countdown while the send is cooling', () => {
        vi.mocked(router.post).mockReset();
        render(<TestSendButton cooldownSeconds={45} guard={runNow} />);

        const button = screen.getByRole('button', {
            name: /Wait .* before sending a test notification/,
        });
        expect(button).toBeDisabled();

        fireEvent.click(button);
        expect(router.post).not.toHaveBeenCalled();
    });

    it('leaves the test button live when nothing is cooling', () => {
        render(<TestSendButton cooldownSeconds={null} guard={runNow} />);
        expect(
            screen.getByText('send test notification').closest('button'),
        ).not.toBeDisabled();
    });

    it('hands the send to the guard instead of posting directly', () => {
        vi.mocked(router.post).mockReset();
        const guard = vi.fn();
        render(<TestSendButton cooldownSeconds={null} guard={guard} />);

        fireEvent.click(screen.getByText('send test notification'));

        expect(guard).toHaveBeenCalledOnce();
        expect(router.post).not.toHaveBeenCalled();
    });
});
