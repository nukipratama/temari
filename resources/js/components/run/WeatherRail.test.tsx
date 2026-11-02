import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import WeatherRail, { railMarkers } from './WeatherRail';

function label(reading: string): HTMLElement | null {
    return document.querySelector(`[data-label="${reading}"]`);
}

describe('railMarkers', () => {
    it('fits the scale to the readings, rounded out to 5° with 2° of margin', () => {
        const { low, high } = railMarkers({
            avg: 27,
            feels: 32,
            dew: 24,
            peak: null,
        });
        expect(low).toBe(20);
        expect(high).toBe(35);
    });

    it('stretches the scale to keep a very hot feels-like on the rail', () => {
        const { high, markers } = railMarkers({
            avg: 33,
            feels: 41,
            dew: 24,
            peak: 35,
        });
        expect(high).toBe(45);
        expect(markers.every((m) => m.pct >= 0 && m.pct <= 100)).toBe(true);
    });

    it('moves a crowded label to the free side of the rail', () => {
        const { markers } = railMarkers({
            avg: 12,
            feels: 10,
            dew: 3,
            peak: null,
        });
        const feels = markers.find((m) => m.reading === 'feels');
        const avg = markers.find((m) => m.reading === 'avg');
        expect(feels?.above).toBe(true);
        expect(avg?.above).toBe(false);
    });

    it('leaves a crowded label in place when the other side is crowded too', () => {
        const { markers } = railMarkers({
            avg: 30,
            feels: 31,
            dew: 30,
            peak: null,
        });
        expect(markers.find((m) => m.reading === 'feels')?.above).toBe(false);
    });
});

describe('WeatherRail', () => {
    it('draws average, feels-like, dew point and a peak that rose 2° or more', () => {
        render(<WeatherRail avg={29} feels={34} dew={23} peak={33} />);
        expect(label('avg')).toHaveTextContent('avg 29°');
        expect(label('feels')).toHaveTextContent('feels 34°');
        expect(label('dew')).toHaveTextContent('dew 23°');
        expect(label('peak')).toHaveTextContent('peak 33°');
        expect(
            screen.getByRole('img', {
                name: 'average 29°C, felt like 34°C, dew point 23°C, peak 33°C',
            }),
        ).toBeInTheDocument();
    });

    it('leaves off a peak within 2° of the average', () => {
        render(<WeatherRail avg={27} feels={32} dew={24} peak={28} />);
        expect(label('peak')).toBeNull();
    });

    it('shows feels-like warmer than the air even when the run was not hot', () => {
        render(<WeatherRail avg={27} feels={32} dew={24} peak={null} />);
        expect(label('feels')).toHaveTextContent('feels 32°');
        expect(document.body).not.toHaveTextContent(/hot/);
    });

    it('shades the gap between average and feels-like, either way round', () => {
        const { rerender } = render(
            <WeatherRail avg={27} feels={32} dew={null} peak={null} />,
        );
        const band = () =>
            document.querySelector<HTMLElement>('[data-felt-band]');
        expect(band()?.style.width).not.toBe('0%');

        rerender(<WeatherRail avg={12} feels={10} dew={3} peak={null} />);
        expect(Number.parseFloat(band()?.style.width ?? '0')).toBeGreaterThan(
            0,
        );
    });

    it('draws no band and no dew marker when those readings are missing', () => {
        render(<WeatherRail avg={28} feels={null} dew={null} peak={33} />);
        expect(document.querySelector('[data-felt-band]')).toBeNull();
        expect(label('dew')).toBeNull();
        expect(label('peak')).toHaveTextContent('peak 33°');
    });

    it('renders nothing when the average is the only reading', () => {
        const { container } = render(
            <WeatherRail avg={28} feels={null} dew={null} peak={29} />,
        );
        expect(container).toBeEmptyDOMElement();
    });
});
