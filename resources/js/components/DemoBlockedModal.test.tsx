import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import DemoBlockedModal from './DemoBlockedModal';

describe('DemoBlockedModal', () => {
    it('renders nothing when closed', () => {
        const { container } = render(<DemoBlockedModal open={false} onClose={vi.fn()} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders the title, body, and both CTAs when open', () => {
        render(<DemoBlockedModal open onClose={vi.fn()} />);
        expect(screen.getByText('Telegram-nya lagi istirahat dulu')).toBeInTheDocument();
        expect(screen.getByText(/Sambungin Strava-mu sendiri/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Sambungkan dengan Strava' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Nanti aja' })).toBeInTheDocument();
    });

    it('exposes the dialog role wired to the title via aria-labelledby', () => {
        render(<DemoBlockedModal open onClose={vi.fn()} />);
        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveAttribute('aria-modal', 'true');
        expect(dialog).toHaveAttribute('aria-labelledby', 'temari-nudge-title');
        expect(document.getElementById('temari-nudge-title')).toHaveTextContent('Telegram-nya lagi istirahat dulu');
    });

    it('posts to /logout when the primary CTA is clicked', () => {
        vi.mocked(router.post).mockReset();
        render(<DemoBlockedModal open onClose={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Sambungkan dengan Strava' }));
        expect(router.post).toHaveBeenCalledWith('/logout');
    });

    it('calls onClose when the dismiss CTA is clicked', () => {
        const onClose = vi.fn();
        render(<DemoBlockedModal open onClose={onClose} />);
        fireEvent.click(screen.getByRole('button', { name: 'Nanti aja' }));
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('calls onClose when the top-left close button is clicked', () => {
        const onClose = vi.fn();
        render(<DemoBlockedModal open onClose={onClose} />);
        fireEvent.click(screen.getByLabelText('Tutup'));
        expect(onClose).toHaveBeenCalledOnce();
    });
});
