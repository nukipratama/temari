import { useMemo } from 'react';
import polylineCodec from '@mapbox/polyline';
import { latLngBounds } from 'leaflet';
import { MapContainer, Polyline, TileLayer } from 'react-leaflet';
// leaflet.css lives in resources/css/app.css (@import). Importing it here would race
// the lazy-load and leave tiles unpositioned on first render.

interface RouteMapProps {
    polyline: string;
}

export default function RouteMap({ polyline }: Readonly<RouteMapProps>) {
    const positions = useMemo<Array<[number, number]>>(
        () => polylineCodec.decode(polyline) as Array<[number, number]>,
        [polyline],
    );

    if (positions.length < 2) {
        return (
            <div className="flex h-56 items-center justify-center rounded-2xl border border-dashed border-line text-sm text-ink-3">
                Rute tidak tersedia
            </div>
        );
    }

    return (
        <div className="overflow-hidden rounded-2xl border border-line">
            <MapContainer
                bounds={latLngBounds(positions)}
                boundsOptions={{ padding: [20, 20] }}
                scrollWheelZoom={false}
                style={{ height: '280px', width: '100%' }}
                attributionControl
            >
                {/* OSMF main tile server. Avoid *.basemaps.cartocdn.com (blocked by uBlock
                    lists) and tile.openstreetmap.de (needs /tiles/osmde/ prefix or all tiles 404). */}
                <TileLayer
                    attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                    url="https://tile.openstreetmap.org/{z}/{x}/{y}.png"
                    maxZoom={19}
                    eventHandlers={{
                        /* v8 ignore next 3 — fires only when the network/tile
                           server returns an error; surfaces a console hint
                           if the map still won't load. */
                        tileerror: (e) =>
                            console.warn('[RouteMap] tile load failed', e.tile?.src ?? '(no src)'),
                    }}
                />
                <Polyline positions={positions} pathOptions={{ color: '#0e7a4c', weight: 4, opacity: 0.9 }} />
            </MapContainer>
        </div>
    );
}

