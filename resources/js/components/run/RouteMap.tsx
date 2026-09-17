import { usePage } from '@inertiajs/react';
import polylineCodec from '@mapbox/polyline';
import { latLngBounds } from 'leaflet';
import { useMemo, useState } from 'react';
import { MapContainer, Polyline, TileLayer } from 'react-leaflet';

import type { SharedProps } from '@/types/inertia';

import { useIsDarkGround } from '@/hooks/useIsDarkGround';
import { PALETTE } from '@/lib/chartTokens';
// leaflet.css lives in resources/css/app.css (@import). Importing it here would race
// the lazy-load and leave tiles unpositioned on first render.

const OSM_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const OSM_ATTRIBUTION =
    '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors';

const CARTO_VOYAGER_URL =
    'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png';
const CARTO_DARK_MATTER_URL =
    'https://{s}.basemaps.cartocdn.com/rastertiles/dark_all/{z}/{x}/{y}.png';
const CARTO_ATTRIBUTION =
    '&copy; <a href="https://carto.com/attributions" target="_blank" rel="noopener noreferrer">CARTO</a> &copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors';

interface RouteMapProps {
    polyline: string;
    /** Run distance in km, shown in the map's accessible label when available. */
    distanceKm?: string;
}

export default function RouteMap({
    polyline,
    distanceKm,
}: Readonly<RouteMapProps>) {
    const [active, setActive] = useState(false);
    const isDark = useIsDarkGround();
    const cartoApiKey = usePage<SharedProps>().props.cartoApiKey ?? '';
    const positions = useMemo<Array<[number, number]>>(
        () => polylineCodec.decode(polyline) as Array<[number, number]>,
        [polyline],
    );

    if (positions.length < 2) {
        return (
            <div className="flex h-56 items-center justify-center rounded-lg border border-dashed border-border text-sm text-text-3">
                Route not available
            </div>
        );
    }

    const mapLabel = distanceKm
        ? `Run route map, ${distanceKm} km`
        : 'Run route map';

    // Anonymous CARTO tiles now render an "API key required" watermark, so the
    // CARTO style only applies once the owner has configured a key; empty
    // falls back to plain OSM tiles rather than show that watermark.
    const tileUrl = cartoApiKey
        ? `${isDark ? CARTO_DARK_MATTER_URL : CARTO_VOYAGER_URL}?key=${cartoApiKey}`
        : OSM_URL;
    const tileAttribution = cartoApiKey ? CARTO_ATTRIBUTION : OSM_ATTRIBUTION;

    // `isolate` confines Leaflet's internal pane/control z-indexes (up to ~1000)
    // to this box so they don't paint over the fixed bottom nav. `role="img"` sits
    // on its own inner div (not this wrapper) — screen readers flatten a
    // `role="img"` element's subtree to just its accessible name, which would hide
    // the tap-to-activate button below from keyboard/AT users entirely.
    return (
        <div className="relative isolate overflow-hidden">
            <div role="img" aria-label={mapLabel}>
                <MapContainer
                    bounds={latLngBounds(positions)}
                    boundsOptions={{ padding: [20, 20] }}
                    scrollWheelZoom={false}
                    style={{ height: '280px', width: '100%' }}
                    attributionControl
                >
                    <TileLayer
                        attribution={tileAttribution}
                        url={tileUrl}
                        subdomains="abcd"
                        maxZoom={19}
                        eventHandlers={{
                            /* v8 ignore next 3 — fires only when the network/tile
                               server returns an error; surfaces a console hint
                               if the map still won't load. */
                            tileerror: (e) =>
                                console.warn(
                                    '[RouteMap] tile load failed',
                                    e.tile?.src ?? '(no src)',
                                ),
                        }}
                    />
                    <Polyline
                        positions={positions}
                        pathOptions={{
                            color: PALETTE.leaf,
                            weight: 4,
                            opacity: 0.9,
                        }}
                    />
                </MapContainer>
            </div>
            {/* A swipe starting on the map pans it instead of scrolling the page (Leaflet
                calls preventDefault on touchmove during drag). Gate real interaction
                behind a tap so a swipe-to-scroll passes through untouched until then,
                the same "tap/click to activate" pattern Google Maps embeds use. */}
            {!active && (
                <button
                    type="button"
                    onClick={() => setActive(true)}
                    aria-label="Activate map to pan and zoom"
                    className="absolute inset-0 z-[1000] flex items-end justify-center bg-transparent p-3"
                >
                    <span className="rounded-full bg-ink/70 px-3 py-1.5 text-label-micro text-cream backdrop-blur-sm">
                        Activate map
                    </span>
                </button>
            )}
        </div>
    );
}
