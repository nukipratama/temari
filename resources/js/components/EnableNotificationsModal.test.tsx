import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import EnableNotificationsModal from './EnableNotificationsModal';

describe('EnableNotificationsModal', () => {
    it('renders nothing when closed', () => {
        const { container } = render(<EnableNotificationsModal open={false} onClose={vi.fn()} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders the title, body, and both CTAs when open', () => {
        render(<EnableNotificationsModal open onClose={vi.fn()} />);
        expect(screen.getByText('Nyalain notifikasi dulu yuk')).toBeInTheDocument();
        expect(screen.getByText(/tiap abis lari sama pas rekap/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Ke Pengaturan' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Nanti aja' })).toBeInTheDocument();
    });

    it('names both channels so neither is presented as the only way', () => {
        render(<EnableNotificationsModal open onClose={vi.fn()} />);
        expect(screen.getByText(/notifikasi HP atau Telegram/)).toBeInTheDocument();
    });

    it('visits Pengaturan when the primary CTA is clicked', () => {
        vi.mocked(router.visit).mockReset();
        render(<EnableNotificationsModal open onClose={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Ke Pengaturan' }));
        expect(router.visit).toHaveBeenCalledWith('/pengaturan');
    });

    it('calls onClose when the dismiss CTA is clicked', () => {
        const onClose = vi.fn();
        render(<EnableNotificationsModal open onClose={onClose} />);
        fireEvent.click(screen.getByRole('button', { name: 'Nanti aja' }));
        expect(onClose).toHaveBeenCalledOnce();
    });
});
