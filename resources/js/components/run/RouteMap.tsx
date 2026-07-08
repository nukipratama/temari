import { useMemo, useState } from 'react';
import polylineCodec from '@mapbox/polyline';
import { latLngBounds } from 'leaflet';
import { MapContainer, Polyline, TileLayer } from 'react-leaflet';
import { DAYBREAK } from '@/lib/chartTokens';
// leaflet.css lives in resources/css/app.css (@import). Importing it here would race
// the lazy-load and leave tiles unpositioned on first render.

interface RouteMapProps {
    polyline: string;
    /** Run distance in km, shown in the map's accessible label when available. */
    distanceKm?: string;
}

export default function RouteMap({ polyline, distanceKm }: Readonly<RouteMapProps>) {
    const [active, setActive] = useState(false);
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

    const mapLabel = distanceKm ? `Peta rute lari, ${distanceKm} km` : 'Peta rute lari';

    // `isolate` confines Leaflet's internal pane/control z-indexes (up to ~1000)
    // to this box so they don't paint over the fixed bottom nav. `role="img"` sits
    // on its own inner div (not this wrapper) — screen readers flatten a
    // `role="img"` element's subtree to just its accessible name, which would hide
    // the tap-to-activate button below from keyboard/AT users entirely.
    return (
        <div className="relative isolate overflow-hidden rounded-2xl border border-line">
            <div
                role="img"
                aria-label={mapLabel}
                className="[&_.leaflet-tile-pane]:[filter:sepia(0.35)_saturate(0.85)_hue-rotate(-6deg)_brightness(1.04)_contrast(0.96)]"
            >
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
                        attribution='&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors'
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
                    <Polyline positions={positions} pathOptions={{ color: DAYBREAK.leaf, weight: 4, opacity: 0.9 }} />
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
                    aria-label="Aktifkan peta untuk menggeser dan memperbesar"
                    className="absolute inset-0 z-[1000] flex items-end justify-center bg-transparent p-3"
                >
                    <span className="rounded-full bg-ink/70 px-3 py-1.5 font-mono text-[11px] uppercase tracking-[0.1em] text-cream backdrop-blur-sm">
                        Ketuk untuk interaktif
                    </span>
                </button>
            )}
        </div>
    );
}

