import { render, screen, waitFor } from '@testing-library/react';
import { createRef } from 'react';
import { describe, expect, it, vi } from 'vitest';

import type { ActivityDetail } from '@/types/inertia';

import RunHero from './RunHero';

// RouteMap is lazy()-loaded and wraps real leaflet/react-leaflet/@mapbox/polyline
// (see its own dedicated test file for those stubs).
vi.mock('@/components/run/RouteMap', () => ({
    default: () => <div data-testid="route-map" />,
}));

function detail(overrides: Partial<ActivityDetail> = {}): ActivityDetail {
    return {
        id: 11,
        activity_id: 99,
        name: 'Morning tempo',
        start_date_local: '2026-02-19T06:52:00',
        distance: 10420,
        elapsed_time: 2912,
        total_elevation_gain: 62,
        average_heartrate: 152,
        max_heartrate: 171,
        trimp_edwards: 118,
        ...overrides,
    };
}

function renderHero(
    overrides: Partial<Parameters<typeof RunHero>[0]> = {},
    detailOverrides: Partial<ActivityDetail> = {},
) {
    return render(
        <RunHero
            detail={detail(detailOverrides)}
            mood="blazing"
            duration="48:32"
            paceSec={279}
            hr={152}
            trimp={118}
            {...overrides}
        />,
    );
}

describe('RunHero', () => {
    it('heads the panel with the as-recorded date, the title and the mood', () => {
        renderHero();
        expect(screen.getByText('19 Feb 2026 · 06:52')).toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'Morning tempo' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Blazing')).toBeInTheDocument();
    });

    it('falls back to "run" when the activity has no name', () => {
        renderHero({}, { name: null });
        expect(screen.getByRole('heading', { name: 'run' })).toBeInTheDocument();
    });

    it('makes distance the headline stat with duration and pace beside it', async () => {
        renderHero();
        expect(screen.getByText('DISTANCE')).toBeInTheDocument();
        await waitFor(() =>
            expect(screen.getByText('10.42')).toBeInTheDocument(),
        );
        expect(screen.getByText('DURATION')).toBeInTheDocument();
        expect(screen.getByText('48:32')).toBeInTheDocument();
        expect(screen.getByText('PACE')).toBeInTheDocument();
        await waitFor(() =>
            expect(screen.getByText('4:39/km')).toBeInTheDocument(),
        );
    });

    it('supports the headline with HR, TRIMP and elevation', async () => {
        renderHero();
        expect(screen.getByText('HR')).toBeInTheDocument();
        expect(screen.getByText('TRIMP')).toBeInTheDocument();
        expect(screen.getByText('ELEVATION')).toBeInTheDocument();
        await waitFor(() =>
            expect(screen.getByText('152')).toBeInTheDocument(),
        );
        expect(screen.getByText('118')).toBeInTheDocument();
        expect(screen.getByText('62')).toBeInTheDocument();
    });

    it('dashes every stat the run never recorded', () => {
        renderHero(
            { paceSec: null, hr: null, trimp: null, duration: '—' },
            { distance: null, total_elevation_gain: null },
        );
        expect(screen.getAllByText('—').length).toBeGreaterThan(3);
    });

    it('hides the share button when the run has no card to share', () => {
        renderHero();
        expect(
            screen.queryByRole('button', { name: /Share/ }),
        ).not.toBeInTheDocument();
    });

    it('offers the share button, and anchors the coach-mark ref on it', () => {
        const onShare = vi.fn();
        const shareRef = createRef<HTMLButtonElement>();
        renderHero({ onShare, shareRef });

        const button = screen.getByRole('button', { name: /Share/ });
        expect(shareRef.current).toBe(button);
        button.click();
        expect(onShare).toHaveBeenCalledOnce();
    });

    it('mounts the route + conditions slab from the same detail', () => {
        renderHero({}, { weather_temp_c: 24, location_name: 'Senayan' });
        expect(screen.getByText(/24°/)).toBeInTheDocument();
        expect(screen.getByText('Senayan')).toBeInTheDocument();
    });
});
