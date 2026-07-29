import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import FlashNotice from './FlashNotice';
import { setMockPage } from '@/test/setup';

const base = { auth: { user: null }, demoLoginEnabled: false } as const;

describe('FlashNotice', () => {
    it('renders nothing when no flash is set', () => {
        setMockPage({ ...base, flash: { success: null, error: null, info: null } });
        const { container } = render(<FlashNotice />);
        expect(container.firstChild).toBeNull();
    });

    it('renders nothing when the flash prop itself is absent', () => {
        setMockPage({ ...base, flash: undefined });
        const { container } = render(<FlashNotice />);
        expect(container.firstChild).toBeNull();
    });

    it('surfaces an info flash politely', () => {
        setMockPage({ ...base, flash: { info: 'Tarikan dari Strava lagi dijeda sebentar. Nanti ketarik lagi otomatis.' } });
        render(<FlashNotice />);
        expect(screen.getByRole('status')).toHaveTextContent('Tarikan dari Strava lagi dijeda sebentar');
    });

    it('surfaces a success flash politely', () => {
        setMockPage({ ...base, flash: { success: 'Zona HR kamu udah kesimpen.' } });
        render(<FlashNotice />);
        expect(screen.getByRole('status')).toHaveTextContent('Zona HR kamu udah kesimpen.');
    });

    it('surfaces an error flash assertively', () => {
        setMockPage({ ...base, flash: { error: 'Gagal narik dari Strava.' } });
        render(<FlashNotice />);
        expect(screen.getByRole('alert')).toHaveTextContent('Gagal narik dari Strava.');
    });

    it('shows one banner only, error first, when several flashes are set at once', () => {
        setMockPage({ ...base, flash: { error: 'Gagal.', info: 'Dijeda.', success: 'Kesimpen.' } });
        render(<FlashNotice />);
        expect(screen.getAllByRole('alert')).toHaveLength(1);
        expect(screen.queryByText('Dijeda.')).not.toBeInTheDocument();
        expect(screen.queryByText('Kesimpen.')).not.toBeInTheDocument();
    });

    it('ignores an empty-string flash', () => {
        setMockPage({ ...base, flash: { info: '' } });
        const { container } = render(<FlashNotice />);
        expect(container.firstChild).toBeNull();
    });

    it('dismisses when the close button is clicked', () => {
        setMockPage({ ...base, flash: { info: 'Nyalakan notifikasi dulu ya.' } });
        render(<FlashNotice />);
        fireEvent.click(screen.getByLabelText('Tutup'));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('stays dismissed when a partial reload replays the same flash', () => {
        setMockPage({ ...base, flash: { info: 'Barusan udah dikirim. Tunggu sebentar ya.' } });
        const { rerender } = render(<FlashNotice />);
        fireEvent.click(screen.getByLabelText('Tutup'));

        setMockPage({ ...base, flash: { info: 'Barusan udah dikirim. Tunggu sebentar ya.' } });
        rerender(<FlashNotice />);
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('re-shows for a fresh flash after a prior dismissal', () => {
        setMockPage({ ...base, flash: { info: 'Nyalakan notifikasi dulu ya.' } });
        const { rerender } = render(<FlashNotice />);
        fireEvent.click(screen.getByLabelText('Tutup'));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();

        setMockPage({ ...base, flash: { success: 'Aku kirim notifikasi tes ya.' } });
        rerender(<FlashNotice />);
        expect(screen.getByRole('status')).toHaveTextContent('Aku kirim notifikasi tes ya.');
    });

    it('clears itself when the next navigation carries no flash', () => {
        setMockPage({ ...base, flash: { success: 'Zona kamu udah disinkron ulang dari Strava.' } });
        const { rerender, container } = render(<FlashNotice />);
        expect(screen.getByRole('status')).toBeInTheDocument();

        setMockPage({ ...base, flash: {} });
        rerender(<FlashNotice />);
        expect(container.firstChild).toBeNull();
    });
});
