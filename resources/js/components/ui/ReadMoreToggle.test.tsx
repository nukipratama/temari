import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import ReadMoreToggle from './ReadMoreToggle';

describe('ReadMoreToggle', () => {
    it('shows "Baca selengkapnya" when collapsed and "Tutup" when expanded', () => {
        const { rerender } = render(
            <ReadMoreToggle expanded={false} onToggle={() => {}} />,
        );
        expect(
            screen.getByRole('button', { name: 'Baca selengkapnya' }),
        ).toBeInTheDocument();
        rerender(<ReadMoreToggle expanded onToggle={() => {}} />);
        expect(
            screen.getByRole('button', { name: 'Tutup' }),
        ).toBeInTheDocument();
    });

    it('calls onToggle when clicked', () => {
        const onToggle = vi.fn();
        render(<ReadMoreToggle expanded={false} onToggle={onToggle} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Baca selengkapnya' }),
        );
        expect(onToggle).toHaveBeenCalledOnce();
    });
});
