import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import SendToTelegramButton from './SendToTelegramButton';

describe('SendToTelegramButton', () => {
    it('posts to the given url when clicked', () => {
        vi.mocked(router.post).mockReset();
        render(<SendToTelegramButton url="/aktivitas/99/telegram" />);
        fireEvent.click(screen.getByText('Kirim ke Telegram'));
        expect(router.post).toHaveBeenCalledWith('/aktivitas/99/telegram', {}, expect.objectContaining({ preserveScroll: true }));
    });

    it('disables the button and shows a spinner label while sending', () => {
        vi.mocked(router.post).mockImplementation((_url, _data, options) => {
            options?.onStart?.({} as never);
        });
        render(<SendToTelegramButton url="/aktivitas/99/telegram" />);
        const button = screen.getByText('Kirim ke Telegram').closest('button')!;
        fireEvent.click(button);
        expect(button).toBeDisabled();
        expect(button).toHaveTextContent('Lagi ngirim…');
    });
});
