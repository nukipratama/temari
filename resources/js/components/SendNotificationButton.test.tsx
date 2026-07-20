import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import SendNotificationButton from './SendNotificationButton';
import { makeUser, setMockPage } from '@/test/setup';

describe('SendNotificationButton', () => {
    it('posts to the given url when clicked', () => {
        vi.mocked(router.post).mockReset();
        render(<SendNotificationButton url="/aktivitas/99/kirim" />);
        fireEvent.click(screen.getByText('Kirim notifikasi'));
        expect(router.post).toHaveBeenCalledWith('/aktivitas/99/kirim', {}, expect.objectContaining({ preserveScroll: true }));
    });

    it('opens the demo-blocked modal instead of posting for a demo user', () => {
        setMockPage({ auth: { user: makeUser({ is_demo: true }) } });
        vi.mocked(router.post).mockReset();
        render(<SendNotificationButton url="/aktivitas/99/kirim" />);
        fireEvent.click(screen.getByText('Kirim notifikasi'));
        expect(router.post).not.toHaveBeenCalledWith('/aktivitas/99/kirim', expect.anything(), expect.anything());
        expect(screen.getByText('Telegram-nya lagi istirahat dulu')).toBeInTheDocument();
    });

    it('closes the demo-blocked modal when its Tutup button is pressed', async () => {
        setMockPage({ auth: { user: makeUser({ is_demo: true }) } });
        render(<SendNotificationButton url="/aktivitas/99/kirim" />);
        fireEvent.click(screen.getByText('Kirim notifikasi'));
        fireEvent.click(screen.getByLabelText('Tutup'));
        await waitFor(() => expect(screen.queryByText('Telegram-nya lagi istirahat dulu')).not.toBeInTheDocument());
    });

    it('opens the enable-notifications nudge (no post) when a real user taps the muted button', () => {
        setMockPage({ auth: { user: makeUser({ is_demo: false }) } });
        vi.mocked(router.post).mockReset();
        render(<SendNotificationButton url="/aktivitas/99/kirim" reachable={false} />);
        fireEvent.click(screen.getByText('Kirim notifikasi'));
        expect(router.post).not.toHaveBeenCalled();
        expect(screen.getByText('Nyalain notifikasi dulu yuk')).toBeInTheDocument();
    });

    it('closes the enable-notifications nudge when its Tutup button is pressed', async () => {
        setMockPage({ auth: { user: makeUser({ is_demo: false }) } });
        render(<SendNotificationButton url="/aktivitas/99/kirim" reachable={false} />);
        fireEvent.click(screen.getByText('Kirim notifikasi'));
        fireEvent.click(screen.getByLabelText('Tutup'));
        await waitFor(() => expect(screen.queryByText('Nyalain notifikasi dulu yuk')).not.toBeInTheDocument());
    });

    it('opens the same enable nudge (not the demo modal) for a demo user tapping the muted button', () => {
        setMockPage({ auth: { user: makeUser({ is_demo: true }) } });
        render(<SendNotificationButton url="/aktivitas/99/kirim" reachable={false} />);
        fireEvent.click(screen.getByText('Kirim notifikasi'));
        expect(screen.getByText('Nyalain notifikasi dulu yuk')).toBeInTheDocument();
        expect(screen.queryByText('Telegram-nya lagi istirahat dulu')).not.toBeInTheDocument();
    });

    it('disables the button and shows a spinner label while sending', () => {
        vi.mocked(router.post).mockImplementation((_url, _data, options) => {
            options?.onStart?.({} as never);
        });
        render(<SendNotificationButton url="/aktivitas/99/kirim" />);
        const button = screen.getByText('Kirim notifikasi').closest('button')!;
        fireEvent.click(button);
        expect(button).toBeDisabled();
        expect(button).toHaveTextContent('Lagi ngirim…');
    });

    it('disables the button and shows a countdown while on cooldown', () => {
        vi.mocked(router.post).mockReset();
        render(<SendNotificationButton url="/aktivitas/99/kirim" retryAfterSeconds={125} />);
        const button = screen.getByLabelText(/tunggu.*sebelum kirim notifikasi/i);
        expect(button).toBeDisabled();
        expect(button).toHaveTextContent('2:05');
        expect(button).not.toHaveTextContent('Kirim notifikasi');
    });

    it('stays clickable when no cooldown is active', () => {
        vi.mocked(router.post).mockReset();
        render(<SendNotificationButton url="/aktivitas/99/kirim" retryAfterSeconds={null} />);
        expect(screen.getByRole('button', { name: 'Kirim notifikasi' })).not.toBeDisabled();
    });
});
