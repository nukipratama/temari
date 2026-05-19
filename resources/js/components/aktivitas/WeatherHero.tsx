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

function tempTone(temp: number | null | undefined): string {
    if (temp == null) return 'text-ink';
    if (temp >= 31) return 'text-mood-cooked';
    if (temp >= 27) return 'text-mood-squished';
    return 'text-brand-700';
}

function weatherGradient(temp: number | null | undefined, rain: boolean): string {
    if (rain) return 'from-mood-spinning/15 via-surface-elev to-brand-50';
    if (temp != null && temp >= 31) return 'from-mood-cooked/15 via-surface-elev to-accent-50';
    return 'from-brand-50 via-surface-elev to-accent-50/60';
}

function weatherIcon(temp: number | null | undefined, rain: boolean): string {
    if (rain) return 'mdi:weather-rainy';
    if (temp != null && temp >= 31) return 'mdi:weather-sunny-alert';
    return 'mdi:weather-partly-cloudy';
}

export default function WeatherHero({ detail }: Readonly<WeatherHeroProps>) {
    const temp = detail.weather_temp_c;
    const humidity = detail.weather_humidity_pct;
    const rain = detail.weather_rain_detected === true;
    const location = detail.location_name;

    if (temp == null && humidity == null && !rain && location == null) {
        return null;
    }

    return (
        <section className={cn('relative overflow-hidden rounded-2xl border border-line p-5 shadow-sm bg-gradient-to-br', weatherGradient(temp, rain))}>
            <div className="flex items-start justify-between gap-4">
                <div>
                    <p className="text-[10px] font-semibold uppercase tracking-wider text-ink-meta">Cuaca lari</p>
                    {temp != null && (
                        <p className={cn('mt-1 text-4xl font-black tabular-nums', tempTone(temp))}>
                            {temp}
                            <span className="ml-0.5 text-xl font-semibold">°C</span>
                        </p>
                    )}
                    <p className="mt-1 text-xs text-ink-meta">
                        {humidity != null && `${humidity}% humidity`}
                        {humidity != null && rain && ' · '}
                        {rain && <span className="font-semibold text-mood-spinning">hujan saat lari</span>}
                    </p>
                </div>
                <span aria-hidden className="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/80 text-ink shadow-sm ring-1 ring-line">
                    <Icon icon={weatherIcon(temp, rain)} width={24} height={24} />
                </span>
            </div>
            {location != null && (
                <p className="mt-3 flex items-center gap-1 text-sm text-ink">
                    <Icon icon="mdi:map-marker" width={14} height={14} aria-hidden className="text-accent-600" />
                    {location}
                </p>
            )}
        </section>
    );
}
