import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { ActivityDetail } from '@/types/inertia';

import MapWeatherPanel from './MapWeatherPanel';

// RouteMap is lazy()-loaded and wraps real leaflet/react-leaflet/@mapbox/polyline
// (see its own dedicated test file for those stubs). Stub it here too so the
// dynamic import resolving after a test's assertions doesn't try to mount the
// real map against jsdom without those stubs.
vi.mock('@/components/run/RouteMap', () => ({
    default: () => <div data-testid="route-map" />,
}));

function detail(overrides: Partial<ActivityDetail> = {}): ActivityDetail {
    return {
        id: 11,
        activity_id: 99,
        name: 'Morning Run',
        start_date_local: '2026-05-10T07:00:00',
        distance: 10000,
        elapsed_time: 3600,
        average_heartrate: 150,
        trimp_edwards: 70,
        weather_temp_c: 32,
        weather_humidity_pct: 80,
        location_name: 'Senayan, Jakarta Pusat',
        ...overrides,
    };
}

describe('MapWeatherPanel', () => {
    it('renders temp + humidity + location when present', () => {
        render(<MapWeatherPanel detail={detail()} />);
        expect(screen.getByText(/32°/)).toBeInTheDocument();
        expect(screen.getByText(/80% lembab/)).toBeInTheDocument();
        // Splits across two lines (place / province): "Senayan" then "Jakarta Pusat".
        expect(screen.getByText('Senayan')).toBeInTheDocument();
        expect(screen.getByText('Jakarta Pusat')).toBeInTheDocument();
    });

    it('renders nothing above the map when there is neither temp nor location', () => {
        render(
            <MapWeatherPanel
                detail={detail({ weather_temp_c: null, location_name: null })}
            />,
        );
        expect(screen.queryByText(/lembab/)).not.toBeInTheDocument();
        expect(screen.queryByText('Senayan')).not.toBeInTheDocument();
    });

    it('hides the humidity line when the reading is missing', () => {
        render(
            <MapWeatherPanel detail={detail({ weather_humidity_pct: null })} />,
        );
        expect(screen.getByText(/32°/)).toBeInTheDocument();
        expect(screen.queryByText(/lembab/)).not.toBeInTheDocument();
    });

    it('keeps a single-segment location on one line', () => {
        render(
            <MapWeatherPanel detail={detail({ location_name: 'Senayan' })} />,
        );
        expect(screen.getByText('Senayan')).toBeInTheDocument();
    });

    it('splits a 4-segment location into place + province,country lines (no truncation)', () => {
        render(
            <MapWeatherPanel
                detail={detail({
                    location_name:
                        'Gelora Bung Karno, Jakarta Pusat, DKI Jakarta, Indonesia',
                })}
            />,
        );
        expect(
            screen.getByText('Gelora Bung Karno, Jakarta Pusat'),
        ).toBeInTheDocument();
        expect(screen.getByText('DKI Jakarta, Indonesia')).toBeInTheDocument();
    });

    it('hides the wind row when weather_wind_speed_kmh is absent', () => {
        render(<MapWeatherPanel detail={detail()} />);
        expect(screen.queryByText(/km\/j/)).not.toBeInTheDocument();
    });

    it('renders the wind row when weather_wind_speed_kmh is present', () => {
        render(
            <MapWeatherPanel detail={detail({ weather_wind_speed_kmh: 18 })} />,
        );
        expect(screen.getByText(/18 km\/j/)).toBeInTheDocument();
        expect(screen.queryByText(/gust/)).not.toBeInTheDocument();
    });

    it('shows the gust reading when it clears the 8 km/j delta threshold', () => {
        render(
            <MapWeatherPanel
                detail={detail({
                    weather_wind_speed_kmh: 18,
                    weather_wind_gust_kmh: 30,
                })}
            />,
        );
        expect(screen.getByText(/gust 30/)).toBeInTheDocument();
    });

    it('hides the gust reading when it is within the 8 km/j delta threshold', () => {
        render(
            <MapWeatherPanel
                detail={detail({
                    weather_wind_speed_kmh: 18,
                    weather_wind_gust_kmh: 24,
                })}
            />,
        );
        expect(screen.getByText(/18 km\/j/)).toBeInTheDocument();
        expect(screen.queryByText(/gust/)).not.toBeInTheDocument();
    });

    it('rotates the direction arrow to the recorded bearing', () => {
        const { container } = render(
            <MapWeatherPanel
                detail={detail({
                    weather_wind_speed_kmh: 18,
                    weather_wind_direction_deg: 135,
                })}
            />,
        );
        expect(
            container.querySelector('[style*="rotate(135deg)"]'),
        ).not.toBeNull();
    });

    it('hides the map area when no polyline is present', () => {
        const { container } = render(
            <MapWeatherPanel detail={detail({ summary_polyline: null })} />,
        );
        expect(
            container.querySelector('.skeleton, .skeleton-on-sky'),
        ).toBeNull();
    });

    it('hides the map area when the polyline is an empty string', () => {
        const { container } = render(
            <MapWeatherPanel detail={detail({ summary_polyline: '' })} />,
        );
        expect(
            container.querySelector('.skeleton, .skeleton-on-sky'),
        ).toBeNull();
    });

    it('shows the map suspense fallback when a polyline IS present', () => {
        const { container } = render(
            <MapWeatherPanel detail={detail({ summary_polyline: 'abc123' })} />,
        );
        expect(
            container.querySelector('.skeleton, .skeleton-on-sky'),
        ).not.toBeNull();
    });

    it('passes the className through to the wrapper', () => {
        const { container } = render(
            <MapWeatherPanel detail={detail()} className="flex" />,
        );
        expect(container.firstElementChild).toHaveClass('flex');
    });
});
