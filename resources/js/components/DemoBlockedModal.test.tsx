import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import DemoBlockedModal from './DemoBlockedModal';

describe('DemoBlockedModal', () => {
    it('renders nothing when closed', () => {
        const { container } = render(
            <DemoBlockedModal open={false} onClose={vi.fn()} />,
        );
        expect(container.firstChild).toBeNull();
    });

    it('renders the title, body, and both CTAs when open', async () => {
        render(<DemoBlockedModal open onClose={vi.fn()} />);
        await screen.findByRole('dialog');
        expect(
            screen.getByText("Telegram's taking a break for now"),
        ).toBeInTheDocument();
        expect(screen.getByText(/Connect your own Strava/)).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Connect Strava' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Not now' }),
        ).toBeInTheDocument();
    });

    it('is a modal dialog named by its title', async () => {
        render(<DemoBlockedModal open onClose={vi.fn()} />);
        await screen.findByRole('dialog');
        const dialog = screen.getByRole('dialog', {
            name: "Telegram's taking a break for now",
        });
        expect(dialog).toHaveAttribute('aria-modal', 'true');
    });

    it('posts to /logout when the primary CTA is clicked', async () => {
        vi.mocked(router.post).mockReset();
        render(<DemoBlockedModal open onClose={vi.fn()} />);
        await screen.findByRole('dialog');
        fireEvent.click(screen.getByRole('button', { name: 'Connect Strava' }));
        expect(router.post).toHaveBeenCalledWith('/logout');
    });

    it('calls onClose when the dismiss CTA is clicked', async () => {
        const onClose = vi.fn();
        render(<DemoBlockedModal open onClose={onClose} />);
        await screen.findByRole('dialog');
        fireEvent.click(screen.getByRole('button', { name: 'Not now' }));
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('calls onClose when the top-left close button is clicked', async () => {
        const onClose = vi.fn();
        render(<DemoBlockedModal open onClose={onClose} />);
        await screen.findByRole('dialog');
        fireEvent.click(screen.getByLabelText('Close'));
        expect(onClose).toHaveBeenCalledOnce();
    });
});
