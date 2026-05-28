import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import PRMomentModal from './PRMomentModal';

describe('PRMomentModal', () => {
    it('renders nothing when pr is null', () => {
        const { container } = render(<PRMomentModal pr={null} onClose={vi.fn()} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders the PR time and category label', () => {
        const pr = { activityId: 42, categoryLabel: '5K', timeDisplay: '22:15' };
        render(<PRMomentModal pr={pr} onClose={vi.fn()} />);
        expect(screen.getByText('22:15')).toBeInTheDocument();
        expect(screen.getByText(/Rekor baru.*5K/)).toBeInTheDocument();
    });

    it('calls onClose when the close button is clicked', () => {
        const onClose = vi.fn();
        const pr = { activityId: 42, categoryLabel: '10K', timeDisplay: '48:30' };
        render(<PRMomentModal pr={pr} onClose={onClose} />);
        fireEvent.click(screen.getByLabelText('Tutup'));
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('renders the "Lihat detail lari" link pointing to the activity', () => {
        const pr = { activityId: 99, categoryLabel: 'Half Marathon', timeDisplay: '1:47:22' };
        render(<PRMomentModal pr={pr} onClose={vi.fn()} />);
        const link = screen.getByRole('link', { name: /Lihat detail lari/ });
        expect(link).toHaveAttribute('href', '/aktivitas/99');
    });

    it('renders the Bagikan button when onShare is provided', () => {
        const pr = { activityId: 1, categoryLabel: '5K', timeDisplay: '21:00' };
        render(<PRMomentModal pr={pr} onClose={vi.fn()} onShare={vi.fn()} />);
        expect(screen.getByRole('button', { name: /Bagikan/ })).toBeInTheDocument();
    });

    it('omits the Bagikan button when onShare is not provided', () => {
        const pr = { activityId: 1, categoryLabel: '5K', timeDisplay: '21:00' };
        render(<PRMomentModal pr={pr} onClose={vi.fn()} />);
        expect(screen.queryByRole('button', { name: /Bagikan/ })).not.toBeInTheDocument();
    });
});
