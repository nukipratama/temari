import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { DayCell, DayRow } from './DayPicker';

describe('DayPicker', () => {
    it('renders a connecting line between cells but not after the last one', () => {
        const { container } = render(
            <DayRow
                items={[
                    <DayCell
                        key="mon"
                        label="Mon"
                        active={false}
                        onClick={vi.fn()}
                    />,
                    <DayCell
                        key="tue"
                        label="Tue"
                        active={false}
                        onClick={vi.fn()}
                    />,
                ]}
            />,
        );

        expect(container.querySelectorAll('[aria-hidden="true"]')).toHaveLength(
            1,
        );
    });

    it('calls onClick when a cell is tapped', () => {
        const onClick = vi.fn();
        render(<DayCell label="Mon" active={false} onClick={onClick} />);

        fireEvent.click(screen.getByRole('button', { name: 'Mon' }));

        expect(onClick).toHaveBeenCalledOnce();
    });

    it('disables a cell that cannot be picked', () => {
        render(
            <DayCell label="Mon" active={false} disabled onClick={vi.fn()} />,
        );

        expect(screen.getByRole('button', { name: 'Mon' })).toBeDisabled();
    });

    it('renders the flag glyph for the persisted long-run day', () => {
        render(<DayCell label="Wed" active longRun onClick={vi.fn()} />);

        expect(
            screen
                .getByRole('button', { name: 'Wed' })
                .querySelector('[data-icon="mdi:flag-checkered"]'),
        ).toBeInTheDocument();
    });

    it('renders the flag glyph on an active flag-candidate cell', () => {
        render(<DayCell label="Wed" active flagCandidate onClick={vi.fn()} />);

        expect(
            screen
                .getByRole('button', { name: 'Wed' })
                .querySelector('[data-icon="mdi:flag-checkered"]'),
        ).toBeInTheDocument();
    });

    it('renders the run glyph on a plain active cell', () => {
        render(<DayCell label="Wed" active onClick={vi.fn()} />);

        expect(
            screen
                .getByRole('button', { name: 'Wed' })
                .querySelector('[data-icon="mdi:run"]'),
        ).toBeInTheDocument();
    });
});
