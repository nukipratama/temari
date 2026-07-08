import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

vi.mock('react-leaflet', () => ({
    MapContainer: ({ children }: { children?: React.ReactNode }) => (
        <div data-testid="map-container">{children}</div>
    ),
    Polyline: ({ positions }: { positions: Array<[number, number]> }) => (
        <div data-testid="polyline" data-points={positions.length} />
    ),
    TileLayer: () => <div data-testid="tile-layer" />,
}));

vi.mock('@mapbox/polyline', () => ({
    default: {
        decode: (s: string) => (s === 'empty' ? [] : s === 'one' ? [[1, 2]] : [[1, 2], [3, 4], [5, 6]]),
    },
}));

vi.mock('leaflet/dist/leaflet.css', () => ({}));

import RouteMap from './RouteMap';

describe('RouteMap', () => {
    it('renders a MapContainer + Polyline + TileLayer when the polyline decodes to ≥2 points', () => {
        render(<RouteMap polyline="good" />);
        expect(screen.getByTestId('map-container')).toBeInTheDocument();
        expect(screen.getByTestId('polyline').getAttribute('data-points')).toBe('3');
    });

    it('gives the map a generic accessible name when no distance is provided', () => {
        render(<RouteMap polyline="good" />);
        expect(screen.getByRole('img', { name: 'Peta rute lari' })).toBeInTheDocument();
    });

    it('threads the distance into the accessible name when provided', () => {
        render(<RouteMap polyline="good" distanceKm="10.42" />);
        expect(screen.getByRole('img', { name: 'Peta rute lari, 10.42 km' })).toBeInTheDocument();
    });

    it('falls back to a placeholder when the polyline decodes to <2 points', () => {
        render(<RouteMap polyline="one" />);
        expect(screen.queryByTestId('polyline')).not.toBeInTheDocument();
        expect(screen.getByText(/Rute tidak tersedia/i)).toBeInTheDocument();
    });

    it('falls back to a placeholder when the polyline decodes to 0 points', () => {
        render(<RouteMap polyline="empty" />);
        expect(screen.getByText(/Rute tidak tersedia/i)).toBeInTheDocument();
    });

    it('gates interaction behind a tap-to-activate overlay, then removes it once tapped', async () => {
        render(<RouteMap polyline="good" />);
        const overlay = screen.getByRole('button', { name: /Aktifkan peta/i });
        expect(overlay).toBeInTheDocument();

        await userEvent.setup().click(overlay);

        expect(screen.queryByRole('button', { name: /Aktifkan peta/i })).not.toBeInTheDocument();
    });
});
