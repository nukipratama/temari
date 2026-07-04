import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import ConnectTelegramModal from './ConnectTelegramModal';

describe('ConnectTelegramModal', () => {
    it('renders nothing when closed', () => {
        const { container } = render(<ConnectTelegramModal open={false} onClose={vi.fn()} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders the title, body, and both CTAs when open', () => {
        render(<ConnectTelegramModal open onClose={vi.fn()} />);
        expect(screen.getByText('Sambungin Telegram dulu yuk')).toBeInTheDocument();
        expect(screen.getByText(/tiap abis lari sama pas rekap mingguan/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Sambungin Telegram' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Nanti aja' })).toBeInTheDocument();
    });

    it('visits Profil when the primary CTA is clicked', () => {
        vi.mocked(router.visit).mockReset();
        render(<ConnectTelegramModal open onClose={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Sambungin Telegram' }));
        expect(router.visit).toHaveBeenCalledWith('/profil');
    });

    it('calls onClose when the dismiss CTA is clicked', () => {
        const onClose = vi.fn();
        render(<ConnectTelegramModal open onClose={onClose} />);
        fireEvent.click(screen.getByRole('button', { name: 'Nanti aja' }));
        expect(onClose).toHaveBeenCalledOnce();
    });
});
