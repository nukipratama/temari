import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import ReadMoreToggle from './ReadMoreToggle';

describe('ReadMoreToggle', () => {
    it('shows "Read more" when collapsed and "Show less" when expanded', () => {
        const { rerender } = render(
            <ReadMoreToggle expanded={false} onToggle={() => {}} />,
        );
        expect(
            screen.getByRole('button', { name: 'Read more' }),
        ).toBeInTheDocument();
        rerender(<ReadMoreToggle expanded onToggle={() => {}} />);
        expect(
            screen.getByRole('button', { name: 'Show less' }),
        ).toBeInTheDocument();
    });

    // The vivid horizon fill is a fill, not text on paper: it only reads as text
    // on a dark sky panel. Both current callers pass onSky, so the paper branch
    // has no live call site — which is exactly why it needs a test.
    it('carries gold as the -ink member on paper and as the vivid fill on sky', () => {
        const { rerender } = render(
            <ReadMoreToggle expanded={false} onToggle={() => {}} />,
        );
        expect(screen.getByRole('button')).toHaveClass('text-horizon-ink');

        rerender(<ReadMoreToggle expanded={false} onToggle={() => {}} onSky />);
        expect(screen.getByRole('button')).toHaveClass('text-horizon');
    });

    it('calls onToggle when clicked', () => {
        const onToggle = vi.fn();
        render(<ReadMoreToggle expanded={false} onToggle={onToggle} />);
        fireEvent.click(screen.getByRole('button', { name: 'Read more' }));
        expect(onToggle).toHaveBeenCalledOnce();
    });
});
