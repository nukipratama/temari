import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { setMockPage } from '@/test/setup';

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

    it('falls back to plain OSM tiles when no CARTO key is configured', () => {
        setTheme('light');
        setMockPage({ cartoApiKey: '' });
        render(<RouteMap polyline="good" />);
        const tile = screen.getByTestId('tile-layer');
        expect(tile.getAttribute('data-url')).toBe(
            'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        );
        expect(tile.getAttribute('data-attribution')).toContain(
            'OpenStreetMap',
        );
        expect(tile.getAttribute('data-attribution')).not.toContain('CARTO');
    });

    it('uses the CARTO Voyager tiles on the light ground when a key is configured', () => {
        setTheme('light');
        setMockPage({ cartoApiKey: 'test-key' });
        render(<RouteMap polyline="good" />);
        const tile = screen.getByTestId('tile-layer');
        expect(tile.getAttribute('data-url')).toBe(
            'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png?key=test-key',
        );
        expect(tile.getAttribute('data-attribution')).toContain('CARTO');
        expect(tile.getAttribute('data-attribution')).toContain(
            'OpenStreetMap',
        );
    });

    it('uses the CARTO Dark Matter tiles on the dark ground when a key is configured', () => {
        setTheme('dark');
        setMockPage({ cartoApiKey: 'test-key' });
        render(<RouteMap polyline="good" />);
        const tile = screen.getByTestId('tile-layer');
        expect(tile.getAttribute('data-url')).toBe(
            'https://{s}.basemaps.cartocdn.com/rastertiles/dark_all/{z}/{x}/{y}.png?key=test-key',
        );
        expect(tile.getAttribute('data-attribution')).toContain('CARTO');
        expect(tile.getAttribute('data-attribution')).toContain(
            'OpenStreetMap',
        );
    });

    it('flips the CARTO style live when the ground changes while mounted', async () => {
        setTheme('light');
        setMockPage({ cartoApiKey: 'test-key' });
        render(<RouteMap polyline="good" />);
        expect(screen.getByTestId('tile-layer').getAttribute('data-url')).toBe(
            'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png?key=test-key',
        );

        act(() => setTheme('dark'));

        await vi.waitFor(() =>
            expect(
                screen.getByTestId('tile-layer').getAttribute('data-url'),
            ).toBe(
                'https://{s}.basemaps.cartocdn.com/rastertiles/dark_all/{z}/{x}/{y}.png?key=test-key',
            ),
        );
    });
});
