import type { PlanSessionSegment } from '@/types/inertia';

import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import MiniSessionBar, { zoneColor } from './MiniSessionBar';

function segment(
    overrides: Partial<PlanSessionSegment> = {},
): PlanSessionSegment {
    return {
        key: 'main',
        minutes: 30,
        zone: 'Z2',
        pace_label: 'easy',
        pace_sec_per_km: 348,
        ...overrides,
    };
}

describe('zoneColor', () => {
    it('maps a known zone to its ramp colour', () => {
        expect(zoneColor('Z5')).toBe('#b8302f');
    });

    it('falls back to a neutral for an unknown zone', () => {
        expect(zoneColor('Z9')).toBe('var(--color-text-3)');
    });
});

describe('MiniSessionBar', () => {
    it('renders nothing on a rest day, which has no segments', () => {
        const { container } = render(<MiniSessionBar segments={[]} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('renders nothing when no segment has a duration yet', () => {
        const { container } = render(
            <MiniSessionBar segments={[segment({ minutes: null })]} />,
        );
        expect(container).toBeEmptyDOMElement();
    });

    it('sizes each slice by its share of the session and colours it by zone', () => {
        const { container } = render(
            <MiniSessionBar
                segments={[
                    segment({ key: 'warmup', minutes: 10, zone: 'Z1' }),
                    segment({ key: 'main', minutes: 30, zone: 'Z4' }),
                ]}
            />,
        );

        const slices = container.querySelectorAll('.rounded-full');
        expect(slices).toHaveLength(2);
        expect(slices[0]).toHaveStyle({ width: '25%' });
        expect(slices[1]).toHaveStyle({ width: '75%' });
    });
});
