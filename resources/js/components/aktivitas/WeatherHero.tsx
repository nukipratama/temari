import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';

interface WeatherDetail {
    weather_temp_c?: number | null;
    weather_humidity_pct?: number | null;
    weather_rain_detected?: boolean | null;
    location_name?: string | null;
}

interface WeatherHeroProps {
    detail: WeatherDetail;
}

type WeatherKind = 'rain' | 'hot' | 'normal';

function weatherKind(temp: number | null | undefined, rain: boolean): WeatherKind {
    if (rain) return 'rain';
    if (temp != null && temp >= 31) return 'hot';
    return 'normal';
}

const KIND_STYLES: Record<WeatherKind, { gradient: string; icon: string }> = {
    rain: { gradient: 'from-mood-mumet/15 via-surface-elev to-leaf/10', icon: 'mdi:weather-rainy' },
    hot: { gradient: 'from-mood-lemes/15 via-surface-elev to-horizon/10', icon: 'mdi:weather-sunny-alert' },
    normal: { gradient: 'from-leaf/10 via-surface-elev to-horizon/10/60', icon: 'mdi:weather-partly-cloudy' },
};

function tempTone(temp: number): string {
    if (temp >= 31) return 'text-mood-lemes';
    if (temp >= 27) return 'text-mood-oleng';
    return 'text-leaf-deep';
}

export default function WeatherHero({ detail }: Readonly<WeatherHeroProps>) {
    const temp = detail.weather_temp_c;
    const humidity = detail.weather_humidity_pct;
    const rain = detail.weather_rain_detected === true;
    const location = detail.location_name;

    if (temp == null && humidity == null && !rain && location == null) {
        return null;
    }

    const style = KIND_STYLES[weatherKind(temp, rain)];

    return (
        <section className={cn('relative overflow-hidden rounded-2xl border border-line p-4 shadow-sm bg-gradient-to-br sm:p-5', style.gradient)}>
            <div className="flex items-start justify-between gap-4">
                <div>
                    <p className="font-mono text-[11px] font-semibold uppercase tracking-wider text-ink-3">Cuaca lari</p>
                    {temp != null && (
                        <p className={cn('mt-1 font-mono text-4xl font-bold tabular-nums', tempTone(temp))}>
                            {temp}
                            <span className="ml-0.5 text-xl font-semibold">°C</span>
                        </p>
                    )}
                    <p className="mt-1 text-xs text-ink-3">
                        {humidity != null && `${humidity}% humidity`}
                        {humidity != null && rain && ' · '}
                        {rain && <span className="font-semibold text-mood-mumet">hujan saat lari</span>}
                    </p>
                </div>
                <span aria-hidden className="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/80 text-ink shadow-sm ring-1 ring-line">
                    <Icon icon={style.icon} width={24} height={24} />
                </span>
            </div>
            {location != null && (
                <p className="mt-3 flex items-center gap-1 text-sm text-ink">
                    <Icon icon="mdi:map-marker" width={14} height={14} aria-hidden className="text-horizon-deep" />
                    {location}
                </p>
            )}
        </section>
    );
}
