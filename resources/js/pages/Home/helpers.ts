import {
    formatMonthDayId,
    formatShortWeekdayDateId,
    isoDateLocal,
    mondayOf,
    parseNaiveLocalDate,
    sundayOf,
} from '@/lib/pace';

export function formatSignedForm(form: number): string {
    return form >= 0 ? `+${form.toFixed(1)}` : form.toFixed(1);
}

/** "Aug 11–17" (same month) or "Aug 31–Sep 6" (crossing a month boundary). */
export function weekRangeLabel(now: Date): string {
    const monday = mondayOf(isoDateLocal(now));
    const sunday = sundayOf(monday);
    if (monday.getMonth() === sunday.getMonth()) {
        const month = monday.toLocaleDateString('en-US', { month: 'short' });
        return `${month} ${monday.getDate()}–${sunday.getDate()}`;
    }
    return `${formatMonthDayId(monday)}–${formatMonthDayId(sunday)}`;
}

export function formatIdDateUpper(iso: string | null): string {
    if (iso == null) return '';
    // Component-parsed (not new Date(iso)) so the naive backend datetime's
    // trailing Z can't shift the weekday/date for non-WIB viewers.
    const date = parseNaiveLocalDate(iso);
    if (date === null) return '';
    return formatShortWeekdayDateId(date).toUpperCase();
}

export function shortenLocation(name: string | null): string | null {
    if (name === null || name === '') return null;
    const parts = name
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean);
    if (parts.length === 0) return null;
    return parts.length === 1 ? parts[0] : `${parts[0]}, ${parts[1]}`;
}

/**
 * The district-level location only, skipping the specific venue/landmark first
 * part: "Gelora Bung Karno, Jakarta Pusat, DKI Jakarta" -> "Jakarta Pusat".
 * Falls back to the sole part when there's no district segment.
 */
export function districtFromLocation(name: string | null): string | null {
    if (name === null || name === '') return null;
    const parts = name
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean);
    if (parts.length === 0) return null;
    return parts[1] ?? parts[0];
}

export function formatWeather(
    tempC: number | null,
    humidityPct: number | null,
    rain: boolean | null,
): string | null {
    const bits: string[] = [];
    if (tempC !== null) bits.push(`${Math.round(tempC)}°C`);
    if (humidityPct !== null) bits.push(`${Math.round(humidityPct)}%`);
    if (rain === true) bits.push('rain');
    return bits.length > 0 ? bits.join(' · ') : null;
}

// Descriptors for Training-load card subtitles. Thresholds are rough
// runner-folklore numbers, not medical advice.
export function ctlHint(ctl: number | null | undefined): string {
    if (ctl == null) return '';
    if (ctl < 25) return 'still building';
    if (ctl < 50) return 'trending up';
    if (ctl < 80) return 'stable';
    return 'high';
}

export function atlHint(atl: number | null | undefined): string {
    if (atl == null) return '';
    if (atl < 25) return 'fresh';
    if (atl < 55) return 'normal';
    if (atl < 85) return 'tired';
    return 'heavy';
}

export function strainHint(strain: number | null | undefined): string {
    if (strain == null) return '';
    if (strain < 250) return 'light';
    if (strain < 500) return 'moderate';
    return 'heavy';
}

export function monotonyHint(monotony: number | null | undefined): string {
    if (monotony == null) return '';
    if (monotony < 1.5) return 'healthy';
    if (monotony < 2) return 'high';
    return 'monotonous';
}

export type RiskTone = 'text-leaf-ink' | 'text-citrus-ink' | 'text-ember-ink';

/** Below `low` reads calm, below `high` reads cautionary, at or above reads alert. */
function riskTone(
    value: number | null | undefined,
    low: number,
    high: number,
): RiskTone {
    if (value == null || value < low) return 'text-leaf-ink';
    if (value < high) return 'text-citrus-ink';
    return 'text-ember-ink';
}

// Training-load card colors, kept on the same risk axis the hints above already
// describe in words (and, for monotony, the same >2.0 threshold Readiness
// backs a session off for) instead of one fixed color per row regardless of
// how extreme the value actually is.
export function atlTone(atl: number | null | undefined): RiskTone {
    return riskTone(atl, 55, 85);
}

export function strainTone(strain: number | null | undefined): RiskTone {
    return riskTone(strain, 250, 500);
}

export function monotonyTone(monotony: number | null | undefined): RiskTone {
    return riskTone(monotony, 1.5, 2);
}
