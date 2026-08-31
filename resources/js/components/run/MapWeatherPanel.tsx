import { lazy, Suspense } from 'react';

import type { ActivityDetail } from '@/types/inertia';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { formatKm } from '@/lib/pace';

const RouteMap = lazy(() => import('@/components/run/RouteMap'));

/**
 * The route map with the run's conditions read underneath it, as one sunken
 * slab. The prototype fills the map half with a decorative "activate map"
 * placeholder; decision P16 puts the real Leaflet map there, which already
 * carries an activate-to-pan tap target of its own.
 */
export default function MapWeatherPanel({
    detail,
    className,
}: Readonly<{ detail: ActivityDetail; className?: string }>) {
    const temp = detail.weather_temp_c;
    const humidity = detail.weather_humidity_pct;
    const location = detail.location_name;
    const windSpeed = detail.weather_wind_speed_kmh;
    const gust = detail.weather_wind_gust_kmh;
    const direction = detail.weather_wind_direction_deg;
    const showGust = gust != null && windSpeed != null && gust - windSpeed >= 8;
    const hasPolyline =
        detail.summary_polyline != null && detail.summary_polyline.length > 0;
    const hasConditions = temp != null || location != null;

    if (!hasPolyline && !hasConditions) {
        return null;
    }

    return (
        <div
            className={cn('overflow-hidden rounded-md bg-muted', className)}
            data-map-weather
        >
            {hasPolyline && (
                <Suspense
                    fallback={<div className="skeleton h-[280px]" aria-hidden />}
                >
                    <RouteMap
                        polyline={detail.summary_polyline ?? ''}
                        distanceKm={formatKm(detail.distance)}
                    />
                </Suspense>
            )}
            {hasConditions && (
                <div className="flex items-center gap-3 px-4 py-3">
                    {temp != null && (
                        <div>
                            <b className="font-mono text-lg font-bold leading-none tabular-nums text-foreground">
                                {Math.round(temp)}°
                                <span className="text-xs">C</span>
                            </b>
                            {humidity != null && (
                                <span className="mt-1 block font-sans text-xs text-text-2">
                                    {Math.round(humidity)}% humidity
                                </span>
                            )}
                        </div>
                    )}
                    {windSpeed != null && (
                        <div className="flex items-center gap-1 font-sans text-xs text-text-2">
                            <Icon
                                icon="mdi:weather-windy"
                                width={12}
                                height={12}
                                aria-hidden
                            />
                            {Math.round(windSpeed)} km/h
                            {showGust && <span>· gust {Math.round(gust)}</span>}
                            {direction != null && (
                                <Icon
                                    icon="mdi:navigation"
                                    width={10}
                                    height={10}
                                    aria-hidden
                                    style={{
                                        transform: `rotate(${direction}deg)`,
                                    }}
                                    className="text-icon-accent"
                                />
                            )}
                        </div>
                    )}
                    {location != null && (
                        <div className="ml-auto min-w-0 border-l border-border-strong pl-3 text-right">
                            {(() => {
                                const [place, region] =
                                    splitLocationLines(location);
                                return (
                                    <>
                                        <b className="block truncate font-sans text-xs font-bold text-foreground">
                                            {place}
                                        </b>
                                        {region && (
                                            <span className="block truncate font-sans text-xs text-text-2">
                                                {region}
                                            </span>
                                        )}
                                    </>
                                );
                            })()}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * Splits a comma-separated reverse-geocoded name ("Gelora Bung Karno, Jakarta
 * Pusat, DKI Jakarta, Indonesia") into a place line and a province/country
 * line, instead of truncating the whole thing to one row. The last two
 * segments (province, country) become the second line; everything before
 * them stays on the first.
 */
function splitLocationLines(location: string): [string, string | null] {
    const parts = location.split(', ');
    if (parts.length <= 1) {
        return [location, null];
    }
    if (parts.length === 2) {
        return [parts[0], parts[1]];
    }
    return [parts.slice(0, -2).join(', '), parts.slice(-2).join(', ')];
}
