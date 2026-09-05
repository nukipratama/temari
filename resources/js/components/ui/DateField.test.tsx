import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import DateField from './DateField';

const original = globalThis.matchMedia;

function setPointer(coarse: boolean) {
    globalThis.matchMedia = vi.fn(() => ({
        matches: coarse,
        addEventListener: () => {},
        removeEventListener: () => {},
    })) as unknown as typeof matchMedia;
}

afterEach(() => {
    globalThis.matchMedia = original;
    vi.restoreAllMocks();
});

function renderField(props: Partial<Parameters<typeof DateField>[0]> = {}) {
    const onChange = vi.fn();
    render(
        <DateField
            id="race_date"
            value="2026-09-15"
            onChange={onChange}
            {...props}
        />,
    );
    return { onChange };
}

describe('DateField', () => {
    it('keeps a real date input so the browser still validates it', () => {
        setPointer(false);
        renderField({ required: true, min: '2026-09-01' });
        const input = document.querySelector('#race_date');
        expect(input).toHaveAttribute('type', 'date');
        expect(input).toBeRequired();
        expect(input).toHaveAttribute('min', '2026-09-01');
    });

    it('offers no calendar trigger of its own on a coarse pointer', () => {
        setPointer(true);
        renderField();
        expect(
            screen.queryByRole('button', { name: 'Choose a date' }),
        ).not.toBeInTheDocument();
        // The native indicator has to stay reachable there.
        expect(document.querySelector('#race_date')?.className).not.toMatch(
            /calendar-picker-indicator/,
        );
    });

    it('hides the native indicator and offers its own trigger on a fine pointer', () => {
        setPointer(false);
        renderField();
        expect(
            screen.getByRole('button', { name: 'Choose a date' }),
        ).toBeInTheDocument();
        expect(document.querySelector('#race_date')?.className).toMatch(
            /calendar-picker-indicator/,
        );
    });

    it('opens the calendar on the trigger and reports the day picked', () => {
        setPointer(false);
        const { onChange } = renderField();
        fireEvent.click(screen.getByRole('button', { name: 'Choose a date' }));

        const dialog = screen.getByRole('dialog', { name: 'Choose a date' });
        expect(dialog).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'Sunday, September 20, 2026' }),
        );
        expect(onChange).toHaveBeenCalledWith('2026-09-20');
        expect(
            screen.queryByRole('dialog', { name: 'Choose a date' }),
        ).not.toBeInTheDocument();
    });

    it('disables days before min rather than letting them be picked', () => {
        setPointer(false);
        const { onChange } = renderField({ min: '2026-09-10' });
        fireEvent.click(screen.getByRole('button', { name: 'Choose a date' }));

        // Named by its full date: a six-week grid carries two days numbered 5.
        const tooEarly = screen.getByRole('button', {
            name: 'Saturday, September 5, 2026',
        });
        expect(tooEarly).toBeDisabled();
        fireEvent.click(tooEarly);
        expect(onChange).not.toHaveBeenCalled();
    });

    it('steps months without changing the selected day', () => {
        setPointer(false);
        const { onChange } = renderField();
        fireEvent.click(screen.getByRole('button', { name: 'Choose a date' }));

        expect(screen.getByText('september 2026')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Next month' }));
        expect(screen.getByText('october 2026')).toBeInTheDocument();
        expect(onChange).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Previous month' }));
        expect(screen.getByText('september 2026')).toBeInTheDocument();
    });

    it('closes on Escape', () => {
        setPointer(false);
        renderField();
        fireEvent.click(screen.getByRole('button', { name: 'Choose a date' }));
        expect(
            screen.getByRole('dialog', { name: 'Choose a date' }),
        ).toBeInTheDocument();

        fireEvent.keyDown(document, { key: 'Escape' });
        expect(
            screen.queryByRole('dialog', { name: 'Choose a date' }),
        ).not.toBeInTheDocument();
    });

    it('reports a typed date the same way as a picked one', () => {
        setPointer(false);
        const { onChange } = renderField();
        fireEvent.change(document.querySelector('#race_date')!, {
            target: { value: '2026-10-02' },
        });
        expect(onChange).toHaveBeenCalledWith('2026-10-02');
    });
});
