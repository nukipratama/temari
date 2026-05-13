import { useMemo } from 'react';
import polylineCodec from '@mapbox/polyline';
import { latLngBounds } from 'leaflet';
import { MapContainer, Polyline, TileLayer } from 'react-leaflet';
// `leaflet.css` lives in [resources/css/app.css] (@import). Importing it
// here too would race the lazy-load and leave tiles unpositioned on the
// first render — confirmed via screenshot from user QA.

interface RouteMapProps {
    /** Strava `summary_polyline` (encoded polyline format). */
    polyline: string;
}

/**
 * Renders the run's GPS trace on an OpenStreetMap tile layer (CartoDB
 * Voyager — readable in both light and dark contexts, no API key).
 * Decoded once via `@mapbox/polyline`. Auto-fits the viewport to the
 * trace bounds; map is non-interactive scroll (we don't want a wheel
 * over the map to hijack the page scroll).
 *
 * Loaded via [[lazy]] from Runs/Show so Leaflet's ~40KB + the polyline
 * codec stay out of the initial dashboard bundle.
 */
export default function RouteMap({ polyline }: Readonly<RouteMapProps>) {
    const positions = useMemo<Array<[number, number]>>(
        () => polylineCodec.decode(polyline) as Array<[number, number]>,
        [polyline],
    );

    if (positions.length < 2) {
        return (
            <div className="flex h-56 items-center justify-center rounded-2xl border border-dashed border-line text-sm text-ink-meta dark:border-line-dark dark:text-ink-meta-dark">
                Rute tidak tersedia
            </div>
        );
    }

    return (
        <div className="overflow-hidden rounded-2xl border border-line dark:border-line-dark">
            <MapContainer
                bounds={latLngBounds(positions)}
                boundsOptions={{ padding: [20, 20] }}
                scrollWheelZoom={false}
                style={{ height: '280px', width: '100%' }}
                attributionControl
            >
                {/* Tile source: OSMF main tile server. Per their tile-usage
                    policy, personal / low-volume use is allowed. Prior
                    attempts during user QA:
                      - `*.basemaps.cartocdn.com` — blocked by uBlock lists
                      - `tile.openstreetmap.de` — `/tiles/osmde/` prefix is
                        required for that server; without it every tile
                        returns 404 (verified via user devtools screenshot). */}
                <TileLayer
                    attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                    url="https://tile.openstreetmap.org/{z}/{x}/{y}.png"
                    maxZoom={19}
                    eventHandlers={{
                        /* v8 ignore next 3 — fires only when the network/tile
                           server returns an error; surfaces a console hint
                           if the map still won't load. */
                        tileerror: (e) =>
                            // eslint-disable-next-line no-console
                            console.warn('[RouteMap] tile load failed', e.tile?.src ?? '(no src)'),
                    }}
                />
                <Polyline positions={positions} pathOptions={{ color: '#2e7d5c', weight: 4, opacity: 0.9 }} />
            </MapContainer>
        </div>
    );
}

