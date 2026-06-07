import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { ComponentProps } from 'react';
import { router } from '@inertiajs/react';
import PRMomentModal from './PRMomentModal';

type Pr = NonNullable<ComponentProps<typeof PRMomentModal>['pr']>;

function pr(overrides: Partial<Pr> = {}): Pr {
    return { activityId: 42, categoryLabel: '5K', timeDisplay: '22:15', ...overrides };
}

describe('PRMomentModal', () => {
    it('renders nothing when pr is null', () => {
        const { container } = render(<PRMomentModal pr={null} onClose={vi.fn()} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders the PR time and category label', () => {
        render(<PRMomentModal pr={pr()} onClose={vi.fn()} />);
        expect(screen.getByText('22:15')).toBeInTheDocument();
        expect(screen.getByText(/Rekor baru.*5K/)).toBeInTheDocument();
    });

    it('shrinks the headline size for long H:MM:SS times so they do not overflow the fixed-width modal', () => {
        const { rerender } = render(
            <PRMomentModal pr={pr({ categoryLabel: 'Marathon', timeDisplay: '2:48:40' })} onClose={vi.fn()} />,
        );
        // 7-char H:MM:SS gets the smaller max so it fits the 390px panel.
        expect(screen.getByText('2:48:40').className).toContain('clamp(56px,18vw,84px)');

        rerender(<PRMomentModal pr={pr({ timeDisplay: '21:00' })} onClose={vi.fn()} />);
        // Short MM:SS keeps the dramatic large size.
        expect(screen.getByText('21:00').className).toContain('clamp(72px,22vw,108px)');
    });

    it('calls onClose when the close button is clicked', () => {
        const onClose = vi.fn();
        render(<PRMomentModal pr={pr({ categoryLabel: '10K', timeDisplay: '48:30' })} onClose={onClose} />);
        fireEvent.click(screen.getByLabelText('Tutup'));
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('exposes a labelled modal dialog', () => {
        render(<PRMomentModal pr={pr()} onClose={vi.fn()} />);
        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveAttribute('aria-modal', 'true');
        expect(dialog).toHaveAttribute('aria-labelledby', 'pr-moment-title');
        // The referenced title element exists.
        expect(document.getElementById('pr-moment-title')).toBeInTheDocument();
    });

    it('closes on the Escape key', () => {
        const onClose = vi.fn();
        render(<PRMomentModal pr={pr()} onClose={onClose} />);
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('moves focus into the dialog when it opens', () => {
        render(<PRMomentModal pr={pr()} onClose={vi.fn()} />);
        const dialog = screen.getByRole('dialog');
        expect(dialog.contains(document.activeElement)).toBe(true);
    });

    it('advances the PR-seen marker on close', () => {
        vi.mocked(router.post).mockClear();
        render(<PRMomentModal pr={pr()} onClose={vi.fn()} />);
        fireEvent.click(screen.getByLabelText('Tutup'));
        expect(router.post).toHaveBeenCalledWith(
            '/api/pr-ledger/seen',
            {},
            expect.objectContaining({ preserveScroll: true, preserveState: true }),
        );
    });

    it('renders the "Lihat detail lari" link pointing to the activity', () => {
        render(<PRMomentModal pr={pr({ activityId: 99, categoryLabel: 'Half Marathon', timeDisplay: '1:47:22' })} onClose={vi.fn()} />);
        const link = screen.getByRole('link', { name: /Lihat detail lari/ });
        expect(link).toHaveAttribute('href', '/aktivitas/99');
    });

    it('renders the Bagikan button when onShare is provided', () => {
        render(<PRMomentModal pr={pr({ timeDisplay: '21:00' })} onClose={vi.fn()} onShare={vi.fn()} />);
        expect(screen.getByRole('button', { name: /Bagikan/ })).toBeInTheDocument();
    });

    it('omits the Bagikan button when onShare is not provided', () => {
        render(<PRMomentModal pr={pr({ timeDisplay: '21:00' })} onClose={vi.fn()} />);
        expect(screen.queryByRole('button', { name: /Bagikan/ })).not.toBeInTheDocument();
    });
});
