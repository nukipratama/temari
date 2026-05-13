import { render, screen } from '@testing-library/react';
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

    it('falls back to a placeholder when the polyline decodes to <2 points', () => {
        render(<RouteMap polyline="one" />);
        expect(screen.queryByTestId('polyline')).not.toBeInTheDocument();
        expect(screen.getByText(/Rute tidak tersedia/i)).toBeInTheDocument();
    });

    it('falls back to a placeholder when the polyline decodes to 0 points', () => {
        render(<RouteMap polyline="empty" />);
        expect(screen.getByText(/Rute tidak tersedia/i)).toBeInTheDocument();
    });
});
