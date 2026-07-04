import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import SendToTelegramButton from './SendToTelegramButton';
import { makeUser, setMockPage } from '@/test/setup';

describe('SendToTelegramButton', () => {
    it('posts to the given url when clicked', () => {
        vi.mocked(router.post).mockReset();
        render(<SendToTelegramButton url="/aktivitas/99/telegram" />);
        fireEvent.click(screen.getByText('Kirim ke Telegram'));
        expect(router.post).toHaveBeenCalledWith('/aktivitas/99/telegram', {}, expect.objectContaining({ preserveScroll: true }));
    });

    it('opens the demo-blocked modal instead of posting for a demo user', () => {
        setMockPage({ auth: { user: makeUser({ is_demo: true }) } });
        vi.mocked(router.post).mockReset();
        render(<SendToTelegramButton url="/aktivitas/99/telegram" />);
        fireEvent.click(screen.getByText('Kirim ke Telegram'));
        expect(router.post).not.toHaveBeenCalledWith('/aktivitas/99/telegram', expect.anything(), expect.anything());
        expect(screen.getByText('Telegram-nya lagi istirahat dulu')).toBeInTheDocument();
    });

    it('opens the connect-Telegram nudge (no post) when a real user taps the muted button', () => {
        setMockPage({ auth: { user: makeUser({ is_demo: false }) } });
        vi.mocked(router.post).mockReset();
        render(<SendToTelegramButton url="/aktivitas/99/telegram" connected={false} />);
        fireEvent.click(screen.getByText('Kirim ke Telegram'));
        expect(router.post).not.toHaveBeenCalled();
        expect(screen.getByText('Sambungin Telegram dulu yuk')).toBeInTheDocument();
    });

    it('opens the same connect nudge (not the demo modal) for a demo user tapping the muted button', () => {
        setMockPage({ auth: { user: makeUser({ is_demo: true }) } });
        render(<SendToTelegramButton url="/aktivitas/99/telegram" connected={false} />);
        fireEvent.click(screen.getByText('Kirim ke Telegram'));
        expect(screen.getByText('Sambungin Telegram dulu yuk')).toBeInTheDocument();
        expect(screen.queryByText('Telegram-nya lagi istirahat dulu')).not.toBeInTheDocument();
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

    it('disables the button and shows a countdown while on cooldown', () => {
        vi.mocked(router.post).mockReset();
        render(<SendToTelegramButton url="/aktivitas/99/telegram" retryAfterSeconds={125} />);
        const button = screen.getByLabelText(/tunggu.*sebelum kirim ke telegram/i);
        expect(button).toBeDisabled();
        expect(button).toHaveTextContent('2:05');
        expect(button).not.toHaveTextContent('Kirim ke Telegram');
    });

    it('stays clickable when no cooldown is active', () => {
        vi.mocked(router.post).mockReset();
        render(<SendToTelegramButton url="/aktivitas/99/telegram" retryAfterSeconds={null} />);
        expect(screen.getByRole('button', { name: 'Kirim ke Telegram' })).not.toBeDisabled();
    });
});
