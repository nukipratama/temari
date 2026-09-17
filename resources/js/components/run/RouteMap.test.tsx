import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('react-leaflet', () => ({
    MapContainer: ({ children }: { children?: React.ReactNode }) => (
        <div data-testid="map-container">{children}</div>
    ),
    Polyline: ({ positions }: { positions: Array<[number, number]> }) => (
        <div data-testid="polyline" data-points={positions.length} />
    ),
    TileLayer: ({ url, attribution }: { url: string; attribution: string }) => (
        <div
            data-testid="tile-layer"
            data-url={url}
            data-attribution={attribution}
        />
    ),
}));

vi.mock('@mapbox/polyline', () => ({
    default: {
        decode: (s: string) =>
            s === 'empty'
                ? []
                : s === 'one'
                  ? [[1, 2]]
                  : [
                        [1, 2],
                        [3, 4],
                        [5, 6],
                    ],
    },
}));

vi.mock('leaflet/dist/leaflet.css', () => ({}));

import RouteMap from './RouteMap';

function setTheme(theme: 'light' | 'dark') {
    document.documentElement.dataset.theme = theme;
}

describe('RouteMap', () => {
    afterEach(() => {
        delete document.documentElement.dataset.theme;
    });

    it('renders a MapContainer + Polyline + TileLayer when the polyline decodes to ≥2 points', () => {
        render(<RouteMap polyline="good" />);
        expect(screen.getByTestId('map-container')).toBeInTheDocument();
        expect(screen.getByTestId('polyline').getAttribute('data-points')).toBe(
            '3',
        );
    });

    it('gives the map a generic accessible name when no distance is provided', () => {
        render(<RouteMap polyline="good" />);
        expect(
            screen.getByRole('img', { name: 'Run route map' }),
        ).toBeInTheDocument();
    });

    it('threads the distance into the accessible name when provided', () => {
        render(<RouteMap polyline="good" distanceKm="10.42" />);
        expect(
            screen.getByRole('img', { name: 'Run route map, 10.42 km' }),
        ).toBeInTheDocument();
    });

    it('falls back to a placeholder when the polyline decodes to <2 points', () => {
        render(<RouteMap polyline="one" />);
        expect(screen.queryByTestId('polyline')).not.toBeInTheDocument();
        expect(screen.getByText(/Route not available/i)).toBeInTheDocument();
    });

    it('falls back to a placeholder when the polyline decodes to 0 points', () => {
        render(<RouteMap polyline="empty" />);
        expect(screen.getByText(/Route not available/i)).toBeInTheDocument();
    });

    it('gates interaction behind a tap-to-activate overlay, then removes it once tapped', async () => {
        render(<RouteMap polyline="good" />);
        const overlay = screen.getByRole('button', { name: /Activate map/i });
        expect(overlay).toBeInTheDocument();

        await userEvent.setup().click(overlay);

        expect(
            screen.queryByRole('button', { name: /Activate map/i }),
        ).not.toBeInTheDocument();
    });

    it('applies the warm sepia tile filter on the light ground', () => {
        setTheme('light');
        render(<RouteMap polyline="good" />);
        const mapImg = screen.getByRole('img', { name: 'Run route map' });
        expect(mapImg.className).toContain('sepia(0.35)');
        expect(mapImg.className).not.toContain('invert(1)');
        expect(
            screen.getByTestId('tile-layer').getAttribute('data-attribution'),
        ).toContain('OpenStreetMap');
    });

    it('applies an invert-based dark tile filter on the dark ground', () => {
        setTheme('dark');
        render(<RouteMap polyline="good" />);
        const mapImg = screen.getByRole('img', { name: 'Run route map' });
        expect(mapImg.className).toContain('invert(1)');
        expect(mapImg.className).not.toContain('sepia(0.35)');
        expect(
            screen.getByTestId('tile-layer').getAttribute('data-attribution'),
        ).toContain('OpenStreetMap');
    });

    it('flips the tile filter live when the ground changes while mounted', async () => {
        setTheme('light');
        render(<RouteMap polyline="good" />);
        const mapImg = screen.getByRole('img', { name: 'Run route map' });
        expect(mapImg.className).toContain('sepia(0.35)');

        act(() => setTheme('dark'));

        await vi.waitFor(() => expect(mapImg.className).toContain('invert(1)'));
    });
});
