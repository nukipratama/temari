import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import EnableNotificationsModal from './EnableNotificationsModal';

describe('EnableNotificationsModal', () => {
    it('renders nothing when closed', () => {
        const { container } = render(
            <EnableNotificationsModal open={false} onClose={vi.fn()} />,
        );
        expect(container.firstChild).toBeNull();
    });

    it('renders the title, body, and both CTAs when open', () => {
        render(<EnableNotificationsModal open onClose={vi.fn()} />);
        expect(
            screen.getByText('Turn on notifications first'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/right after every run and at recap/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Go to Settings' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Not now' }),
        ).toBeInTheDocument();
    });

    it('names both channels so neither is presented as the only way', () => {
        render(<EnableNotificationsModal open onClose={vi.fn()} />);
        expect(
            screen.getByText(/Push notifications or Telegram/),
        ).toBeInTheDocument();
    });

    it('visits Settings when the primary CTA is clicked', () => {
        vi.mocked(router.visit).mockReset();
        render(<EnableNotificationsModal open onClose={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Go to Settings' }));
        expect(router.visit).toHaveBeenCalledWith('/settings');
    });

    it('calls onClose when the dismiss CTA is clicked', () => {
        const onClose = vi.fn();
        render(<EnableNotificationsModal open onClose={onClose} />);
        fireEvent.click(screen.getByRole('button', { name: 'Not now' }));
        expect(onClose).toHaveBeenCalledOnce();
    });
});
