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

    it('calls onToggle when clicked', () => {
        const onToggle = vi.fn();
        render(<ReadMoreToggle expanded={false} onToggle={onToggle} />);
        fireEvent.click(screen.getByRole('button', { name: 'Read more' }));
        expect(onToggle).toHaveBeenCalledOnce();
    });
});
