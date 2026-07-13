import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import Pengaturan from './Index';
import { makeUser, setMockPage } from '@/test/setup';

const connectedTelegram = {
    connected: true,
    username: 'ada_runs',
    connect_url: null,
    notify_post_run: true,
    notify_weekly_recap: false,
    notify_monthly_recap: true,
    notify_daily_briefing: false,
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
        expect(screen.getByText('Pengaturan')).toBeInTheDocument();
        expect(screen.getByText('Notifikasi Telegram')).toBeInTheDocument();
        expect(screen.getByText('Zona HR')).toBeInTheDocument();
        expect(screen.getByText('Hapus akun')).toBeInTheDocument();
    });

    it('links the Zona HR row to the zones page', () => {
        render(<Pengaturan />);
        expect(screen.getByText('Zona HR').closest('a')).toHaveAttribute('href', '/pengaturan/zona');
    });

    it('shows the Telegram connect link when not connected', () => {
        const telegram = {
            connected: false,
            username: null,
            connect_url: 'https://t.me/temari_bot?start=tok',
            notify_post_run: true,
            notify_weekly_recap: true,
            notify_monthly_recap: true,
            notify_daily_briefing: false,
        };
        render(<Pengaturan telegram={telegram} />);
        expect(screen.getByText('Telegram').closest('a')).toHaveAttribute('href', 'https://t.me/temari_bot?start=tok');
    });

    it('shows the preference toggles when Telegram is connected', () => {
        render(<Pengaturan telegram={connectedTelegram} />);
        expect(screen.getByText(/Telegram aktif/)).toBeInTheDocument();
        expect(screen.getByRole('switch', { name: 'Cerita abis lari' })).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByRole('switch', { name: 'Rekap mingguan' })).toHaveAttribute('aria-checked', 'false');
        expect(screen.getByRole('switch', { name: 'Rekap bulanan' })).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByRole('switch', { name: 'Ringkasan harian' })).toHaveAttribute('aria-checked', 'false');
    });

    it('patches preferences when a toggle is flipped, carrying all current values', () => {
        vi.mocked(router.patch).mockReset();
        render(<Pengaturan telegram={connectedTelegram} />);

        fireEvent.click(screen.getByRole('switch', { name: 'Rekap mingguan' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/profil/telegram',
            { notify_post_run: true, notify_weekly_recap: true, notify_monthly_recap: true, notify_daily_briefing: false },
            { preserveScroll: true },
        );
    });

    it('disconnects via DELETE when Putuskan is clicked', () => {
        vi.mocked(router.delete).mockReset();
        render(<Pengaturan telegram={connectedTelegram} />);

        fireEvent.click(screen.getByText('Putuskan'));

        expect(router.delete).toHaveBeenCalledWith('/profil/telegram', { preserveScroll: true });
    });

    it('posts a test notification when "Kirim notifikasi tes" is clicked', () => {
        vi.mocked(router.post).mockReset();
        render(<Pengaturan telegram={connectedTelegram} />);

        fireEvent.click(screen.getByText('Kirim notifikasi tes'));

        expect(router.post).toHaveBeenCalledWith('/profil/telegram/test', {}, { preserveScroll: true });
    });

    it('opens the demo-blocked modal instead of patching when a demo user flips a toggle', () => {
        setMockPage({ auth: { user: makeUser({ is_demo: true }) }, flash: {}, demoLoginEnabled: false });
        vi.mocked(router.patch).mockReset();
        render(<Pengaturan telegram={connectedTelegram} />);

        const toggle = screen.getByRole('switch', { name: 'Rekap mingguan' });
        fireEvent.click(toggle);

        expect(router.patch).not.toHaveBeenCalled();
        expect(toggle).toHaveAttribute('aria-checked', 'false');
        expect(screen.getByText('Telegram-nya lagi istirahat dulu')).toBeInTheDocument();
    });

    it('opens a confirmation before deleting the account', () => {
        vi.mocked(router.delete).mockReset();
        render(<Pengaturan />);

        fireEvent.click(screen.getByText('Hapus akun'));
        expect(screen.getByText('Yakin mau hapus akun?')).toBeInTheDocument();
        // Nothing is deleted until the user confirms.
        expect(router.delete).not.toHaveBeenCalled();
    });

    it('deletes the account via DELETE /akun when confirmed', () => {
        vi.mocked(router.delete).mockReset();
        render(<Pengaturan />);

        fireEvent.click(screen.getByText('Hapus akun'));
        fireEvent.click(screen.getByRole('button', { name: /Ya, hapus akunku/ }));

        expect(router.delete).toHaveBeenCalledWith('/akun');
    });

    it('dismisses the confirmation without deleting', async () => {
        vi.mocked(router.delete).mockReset();
        render(<Pengaturan />);

        fireEvent.click(screen.getByText('Hapus akun'));
        expect(screen.getByText('Yakin mau hapus akun?')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Nanti aja' }));
        await waitFor(() => {
            expect(screen.queryByText('Yakin mau hapus akun?')).not.toBeInTheDocument();
        });
        expect(router.delete).not.toHaveBeenCalled();
    });
});
