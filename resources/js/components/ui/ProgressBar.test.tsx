import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import ProgressBar from './ProgressBar';

describe('ProgressBar', () => {
    it('renders a progressbar with the rounded percent value', () => {
        render(<ProgressBar value={0.5} ariaLabel="Target" />);
        const bar = screen.getByRole('progressbar', { name: 'Target' });
        expect(bar).toHaveAttribute('aria-valuenow', '50');
        expect(bar).toHaveAttribute('aria-valuemin', '0');
        expect(bar).toHaveAttribute('aria-valuemax', '100');
    });

    it('sets the fill width from the ratio', () => {
        const { container } = render(<ProgressBar value={0.42} />);
        const fill = container.querySelector('[style]') as HTMLElement;
        expect(fill.style.width).toBe('42%');
    });

    it('clamps values above 1 and below 0', () => {
        const { rerender } = render(<ProgressBar value={2} ariaLabel="x" />);
        expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '100');
        rerender(<ProgressBar value={-1} ariaLabel="x" />);
        expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '0');
    });

    it('treats NaN as 0', () => {
        render(<ProgressBar value={Number.NaN} ariaLabel="x" />);
        expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '0');
    });

    it.each([
        ['horizon', 'bg-horizon'],
        ['sky', 'bg-sky'],
    ] as const)('colors the fill with tone %s', (tone, expected) => {
        const { container } = render(<ProgressBar value={0.5} tone={tone} />);
        expect(container.querySelector(`.${expected}`)).not.toBeNull();
    });

    it('uses the cream-deep md track by default and the glass sm track', () => {
        const { container, rerender } = render(<ProgressBar value={0.5} />);
        expect(container.firstElementChild).toHaveClass('h-2');
        expect(container.firstElementChild).toHaveClass('bg-cream-deep');
        rerender(<ProgressBar value={0.5} size="sm" />);
        expect(container.firstElementChild).toHaveClass('h-1.5');
    });
});
