import { Icon } from '@iconify/react';
import { lazy, Suspense } from 'react';

import type { ActivityDetail } from '@/types/inertia';

import Eyebrow from '@/components/ui/Eyebrow';
import { cn } from '@/lib/cn';
import { formatKm } from '@/lib/pace';

const RouteMap = lazy(() => import('@/components/run/RouteMap'));

export default function MapWeatherPanel({
    detail,
    className,
}: Readonly<{ detail: ActivityDetail; className?: string }>) {
    const temp = detail.weather_temp_c;
    const humidity = detail.weather_humidity_pct;
    const location = detail.location_name;
    const hasPolyline =
        detail.summary_polyline != null && detail.summary_polyline.length > 0;
    const windSpeed = detail.weather_wind_speed_kmh;
    const gust = detail.weather_wind_gust_kmh;
    const direction = detail.weather_wind_direction_deg;
    const showGust = gust != null && windSpeed != null && gust - windSpeed >= 8;

    return (
        <div className={cn('relative flex flex-col gap-2', className)}>
            {(temp != null || location != null) && (
                <div className="flex items-baseline gap-3">
                    {temp != null && (
                        <div>
                            <div className="font-sans text-2xl font-bold leading-none tabular-nums">
                                {Math.round(temp)}°
                                <span className="text-sm font-medium">C</span>
                            </div>
                            {humidity != null && (
                                <Eyebrow
                                    token="micro"
                                    tone="cream"
                                    className="mt-1"
                                >
                                    {Math.round(humidity)}% humidity
                                </Eyebrow>
                            )}
                            {windSpeed != null && (
                                <Eyebrow
                                    token="micro"
                                    tone="cream"
                                    className="mt-0.5 flex items-center gap-1"
                                >
                                    <Icon
                                        icon="mdi:weather-windy"
                                        width={11}
                                        height={11}
                                        aria-hidden
                                    />
                                    {Math.round(windSpeed)} km/h
                                    {showGust && (
                                        <span>· gust {Math.round(gust)}</span>
                                    )}
                                    {direction != null && (
                                        <Icon
                                            icon="mdi:navigation"
                                            width={10}
                                            height={10}
                                            aria-hidden
                                            style={{
                                                transform: `rotate(${direction}deg)`,
                                            }}
                                            className="text-horizon"
                                        />
                                    )}
                                </Eyebrow>
                            )}
                        </div>
                    )}
                    {location != null && (
                        <div className="min-w-0 flex-1 border-l border-cream/15 pl-3">
                            {(() => {
                                const [place, region] =
                                    splitLocationLines(location);
                                return (
                                    <>
                                        <div className="truncate font-display text-headline-xs">
                                            {place}
                                        </div>
                                        {region && (
                                            <Eyebrow
                                                token="micro"
                                                className="mt-0.5 truncate text-cream/70"
                                            >
                                                {region}
                                            </Eyebrow>
                                        )}
                                    </>
                                );
                            })()}
                        </div>
                    )}
                </div>
            )}
            {hasPolyline && (
                <div className="overflow-hidden rounded-xl bg-cream/[0.04]">
                    <Suspense
                        fallback={
                            <div
                                className="skeleton-on-sky h-[180px]"
                                aria-hidden
                            />
                        }
                    >
                        <RouteMap
                            polyline={detail.summary_polyline ?? ''}
                            distanceKm={formatKm(detail.distance)}
                        />
                    </Suspense>
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
