import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { IntervalsIcuChip, intervalsIcuReason } from './IntervalsIcuStatus';

describe('IntervalsIcuChip', () => {
    it('says the workout is on intervals.icu once sent', () => {
        render(<IntervalsIcuChip status="sent" />);

        expect(screen.getByText('on intervals.icu')).toBeInTheDocument();
    });

    it.each(['no_threshold_hr', 'unreachable', 'broken'] as const)(
        'says not sent when %s',
        (status) => {
            render(<IntervalsIcuChip status={status} />);

            expect(screen.getByText('not sent')).toBeInTheDocument();
        },
    );
});

describe('intervalsIcuReason', () => {
    it('gives no reason once sent', () => {
        expect(intervalsIcuReason('sent')).toBeNull();
    });

    it('says why the workout is not on intervals.icu', () => {
        expect(intervalsIcuReason('no_threshold_hr')).toBe(
            'not sent to intervals.icu: set your threshold heart rate there first.',
        );
        expect(intervalsIcuReason('unreachable')).toBe(
            "couldn't reach intervals.icu. trying again.",
        );
        expect(intervalsIcuReason('broken')).toBe(
            'intervals.icu stopped accepting your key. reconnect it in settings.',
        );
    });
});
