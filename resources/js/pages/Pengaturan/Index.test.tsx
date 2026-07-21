import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import Pengaturan from './Index';
import { makeUser, setMockPage } from '@/test/setup';

const connectedTelegram = {
    connected: true,
    username: 'ada_runs',
    connect_url: null,
};

const prefs = {
    post_run: true,
    weekly_recap: false,
    monthly_recap: true, telegram_enabled: true, push_enabled: true,
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
        expect(screen.getByText('Notifikasi')).toBeInTheDocument();
        expect(screen.getByText('Telegram')).toBeInTheDocument();
        expect(screen.getByText('Zona HR')).toBeInTheDocument();
        expect(screen.getByText('Hapus akun')).toBeInTheDocument();
    });

    // The page used to open with a bare <h1>Pengaturan</h1>, the only screen in
    // the app not using the editorial header every other page shares.
    it('opens with the editorial header rather than a bare title', () => {
        render(<Pengaturan />);
        expect(screen.getByText('Pengaturan')).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Atur Temari, sesuai kamu.');
    });

    // What gets sent and where it goes were three separate sections; they are
    // now two labelled groups inside one Notifikasi card.
    it('groups the notification settings by what and where', () => {
        render(<Pengaturan />);
        expect(screen.getByText('Apa yang dikirim')).toBeInTheDocument();
        expect(screen.getByText('Ke mana')).toBeInTheDocument();
    });

    // No back affordance anywhere: Pengaturan is one tap from the Aku tab and
    // from the avatar menu on every page, so a breadcrumb has no job here.
    it('has no back link', () => {
        render(<Pengaturan />);
        expect(screen.queryByRole('link', { name: /^Aku$/ })).not.toBeInTheDocument();
    });

    it('tints the destructive row so it stops reading as routine', () => {
        render(<Pengaturan />);
        expect(screen.getByText('Hapus akun')).toHaveClass('text-ember-deep');
    });

    it('shows the Telegram connect link when not connected', () => {
        const telegram = {
            connected: false,
            username: null,
            connect_url: 'https://t.me/temari_bot?start=tok',
        };
        render(<Pengaturan telegram={telegram} />);
        expect(screen.getByRole('link', { name: /Telegram/ })).toHaveAttribute('href', 'https://t.me/temari_bot?start=tok');
    });

    it('shows the channel-neutral preference toggles from notificationPrefs', () => {
        render(<Pengaturan notificationPrefs={prefs} />);
        expect(screen.getByRole('switch', { name: 'Cerita abis lari' })).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByRole('switch', { name: 'Rekap mingguan' })).toHaveAttribute('aria-checked', 'false');
        expect(screen.getByRole('switch', { name: 'Rekap bulanan' })).toHaveAttribute('aria-checked', 'true');
    });

    it('patches the channel-neutral preferences when a toggle is flipped, carrying all current values', () => {
        vi.mocked(router.patch).mockReset();
        render(<Pengaturan notificationPrefs={prefs} />);

        fireEvent.click(screen.getByRole('switch', { name: 'Rekap mingguan' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/profil/notifikasi',
            {
                post_run: true,
                weekly_recap: true,
                monthly_recap: true,
                telegram_enabled: true,
                push_enabled: true,
            },
            { preserveScroll: true },
        );
    });

    it('posts a test notification when "Kirim notifikasi tes" is clicked', () => {
        vi.mocked(router.post).mockReset();
        render(<Pengaturan />);

        fireEvent.click(screen.getByText('Kirim notifikasi tes'));

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

        const button = screen.getByRole('button', { name: /Tunggu .* sebelum kirim notifikasi tes/ });
        expect(button).toBeDisabled();

        fireEvent.click(button);
        expect(router.post).not.toHaveBeenCalled();
    });

    it('leaves the test button live when nothing is cooling', () => {
        render(<Pengaturan testCooldownSeconds={null} />);
        expect(screen.getByText('Kirim notifikasi tes').closest('button')).not.toBeDisabled();
    });

    it('opens the demo-blocked modal instead of patching when a demo user flips a toggle', () => {
        setMockPage({ auth: { user: makeUser({ is_demo: true }) }, flash: {}, demoLoginEnabled: false });
        vi.mocked(router.patch).mockReset();
        render(<Pengaturan notificationPrefs={prefs} />);

        const toggle = screen.getByRole('switch', { name: 'Rekap mingguan' });
        fireEvent.click(toggle);

        expect(router.patch).not.toHaveBeenCalled();
        expect(toggle).toHaveAttribute('aria-checked', 'false');
        expect(screen.getByText('Telegram-nya lagi istirahat dulu')).toBeInTheDocument();
    });

    it('disconnects via DELETE when Putuskan is clicked', () => {
        vi.mocked(router.delete).mockReset();
        render(<Pengaturan telegram={connectedTelegram} />);

        fireEvent.click(screen.getByText('Putuskan'));

        expect(router.delete).toHaveBeenCalledWith('/profil/telegram', { preserveScroll: true });
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
