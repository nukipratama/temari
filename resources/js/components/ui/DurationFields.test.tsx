import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';

import DurationFields, {
    type Duration,
    durationFromSec,
    durationSec,
} from './DurationFields';

function Harness({
    initial = { hours: 0, minutes: 50, seconds: 0 },
    onValue,
    ...props
}: Readonly<{
    initial?: Duration;
    onValue?: (value: Duration) => void;
    withSeconds?: boolean;
    labelPrefix?: string;
}>) {
    const [value, setValue] = useState(initial);

    return (
        <DurationFields
            value={value}
            onChange={(next) => {
                setValue(next);
                onValue?.(next);
            }}
            {...props}
        />
    );
}

describe('DurationFields', () => {
    it('renders hours and minutes only by default', () => {
        render(<Harness />);

        expect(screen.getByLabelText('Hours')).toHaveValue(0);
        expect(screen.getByLabelText('Minutes')).toHaveValue(50);
        expect(screen.queryByLabelText('Seconds')).not.toBeInTheDocument();
    });

    it('adds seconds on request and prefixes the labels', () => {
        render(<Harness withSeconds labelPrefix="finish " />);

        expect(screen.getByLabelText('Finish seconds')).toBeInTheDocument();
    });

    it('reports typed values as numbers', () => {
        const onValue = vi.fn();
        render(<Harness onValue={onValue} />);

        fireEvent.change(screen.getByLabelText('Minutes'), {
            target: { value: '25' },
        });

        expect(onValue).toHaveBeenLastCalledWith({
            hours: 0,
            minutes: 25,
            seconds: 0,
        });
    });

    it('keeps an emptied field empty, reads it as 0, and shows 0 after blur', () => {
        const onValue = vi.fn();
        render(<Harness onValue={onValue} />);
        const minutes = screen.getByLabelText('Minutes') as HTMLInputElement;

        fireEvent.change(minutes, { target: { value: '' } });

        expect(minutes.value).toBe('');
        expect(onValue).toHaveBeenLastCalledWith({
            hours: 0,
            minutes: 0,
            seconds: 0,
        });

        fireEvent.blur(minutes);
        expect(minutes.value).toBe('0');
    });

    it('shows what is typed after an emptied field is refilled', () => {
        render(<Harness />);
        const minutes = screen.getByLabelText('Minutes') as HTMLInputElement;

        fireEvent.change(minutes, { target: { value: '' } });
        fireEvent.change(minutes, { target: { value: '7' } });

        expect(minutes.value).toBe('7');
    });

    it('renders extra controls in the same row', () => {
        render(
            <DurationFields
                value={{ hours: 0, minutes: 0, seconds: 0 }}
                onChange={() => {}}
            >
                <button type="button">save</button>
            </DurationFields>,
        );

        expect(
            screen.getByRole('button', { name: 'save' }),
        ).toBeInTheDocument();
    });
});

describe('duration helpers', () => {
    it('round-trips seconds through parts', () => {
        expect(durationFromSec(5_430)).toEqual({
            hours: 1,
            minutes: 30,
            seconds: 30,
        });
        expect(durationSec(durationFromSec(5_430))).toBe(5_430);
    });
});
