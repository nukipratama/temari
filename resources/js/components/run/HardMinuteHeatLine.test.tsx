import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { RunHardMinutes } from '@/types/inertia';

import HardMinuteHeatLine from './HardMinuteHeatLine';

const hotEasyRun: RunHardMinutes = {
    minutes: 0,
    hr_only_minutes: 31.5,
    easy_ceiling_sec_per_km: 405,
    per_km: null,
};

describe('HardMinuteHeatLine', () => {
    it('names the heart-rate-only count and the heat behind it on a run that counted as easy', () => {
        render(
            <HardMinuteHeatLine
                hardMinutes={hotEasyRun}
                feelsLikeC={33.4}
                dewPointC={23.6}
            />,
        );

        expect(
            screen.getByText(
                'by HR alone 31.5 · 33° feels-like · dew point 24° · counted as easy',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'pace stayed slower than your 6:45/km easy ceiling, so every minute counted as easy.',
            ),
        ).toBeInTheDocument();
    });

    it('says how many minutes eased off a run that also counted some hard, without weather', () => {
        render(
            <HardMinuteHeatLine
                hardMinutes={{
                    ...hotEasyRun,
                    minutes: 12,
                    hr_only_minutes: 20.5,
                }}
                feelsLikeC={null}
                dewPointC={null}
            />,
        );

        expect(
            screen.getByText('by HR alone 20.5 · 12 counted hard'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                '8.5 of those minutes came slower than your 6:45/km easy ceiling and counted as easy.',
            ),
        ).toBeInTheDocument();
    });

    it.each([
        ['under a minute eased', { minutes: 10, hr_only_minutes: 10.6 }],
        ['no easy ceiling', { easy_ceiling_sec_per_km: null }],
    ])('stays out of the way with %s', (_, override) => {
        const { container } = render(
            <HardMinuteHeatLine
                hardMinutes={{ ...hotEasyRun, ...override }}
                feelsLikeC={33}
                dewPointC={24}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });
});
