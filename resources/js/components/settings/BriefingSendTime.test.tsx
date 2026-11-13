import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import BriefingSendTime from './BriefingSendTime';

describe('BriefingSendTime', () => {
    it('saves a chosen send time as minutes once the field is left', () => {
        const onChange = vi.fn();
        render(
            <BriefingSendTime
                minute={null}
                disabled={false}
                onChange={onChange}
            />,
        );
        const field = screen.getByLabelText('send the morning briefing at');

        fireEvent.change(field, { target: { value: '05:30' } });
        expect(onChange).not.toHaveBeenCalled();

        fireEvent.blur(field);
        expect(onChange).toHaveBeenCalledExactlyOnceWith(330);
    });

    it('snaps a time off the quarter hour back to the quarter hour before it', () => {
        const onChange = vi.fn();
        render(
            <BriefingSendTime
                minute={null}
                disabled={false}
                onChange={onChange}
            />,
        );
        const field = screen.getByLabelText('send the morning briefing at');

        fireEvent.change(field, { target: { value: '05:10' } });
        fireEvent.blur(field);

        expect(onChange).toHaveBeenCalledExactlyOnceWith(300);
        expect(field).toHaveValue('05:00');
    });

    it('puts back the saved time instead of saving one inside the evening quiet hours', () => {
        const onChange = vi.fn();
        render(
            <BriefingSendTime
                minute={null}
                disabled={false}
                onChange={onChange}
            />,
        );
        const field = screen.getByLabelText('send the morning briefing at');

        fireEvent.change(field, { target: { value: '22:30' } });
        fireEvent.blur(field);

        expect(onChange).not.toHaveBeenCalled();
        expect(field).toHaveValue('');
    });

    it('says why a time from 22:00 was put back until the next edit', () => {
        render(
            <BriefingSendTime
                minute={null}
                disabled={false}
                onChange={vi.fn()}
            />,
        );
        const field = screen.getByLabelText('send the morning briefing at');
        expect(screen.queryByText('before 22:00 only')).not.toBeInTheDocument();

        fireEvent.change(field, { target: { value: '22:30' } });
        fireEvent.blur(field);
        expect(screen.getByText('before 22:00 only')).toBeInTheDocument();

        fireEvent.change(field, { target: { value: '05:30' } });
        expect(screen.queryByText('before 22:00 only')).not.toBeInTheDocument();
    });

    it('shows a saved send time and clears it back to automatic', () => {
        const onChange = vi.fn();
        render(
            <BriefingSendTime
                minute={4 * 60 + 45}
                disabled={false}
                onChange={onChange}
            />,
        );
        const field = screen.getByLabelText('send the morning briefing at');
        expect(field).toHaveValue('04:45');

        fireEvent.click(screen.getByRole('button', { name: 'clear' }));

        expect(field).toHaveValue('');
        expect(onChange).toHaveBeenCalledExactlyOnceWith(null);
        expect(
            screen.queryByRole('button', { name: 'clear' }),
        ).not.toBeInTheDocument();
    });

    it('disables the field and the clear button together', () => {
        render(
            <BriefingSendTime
                minute={4 * 60 + 45}
                disabled
                onChange={vi.fn()}
            />,
        );

        expect(
            screen.getByLabelText('send the morning briefing at'),
        ).toBeDisabled();
        expect(screen.getByRole('button', { name: 'clear' })).toBeDisabled();
    });
});
