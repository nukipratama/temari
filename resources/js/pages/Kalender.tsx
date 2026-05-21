import { Head, Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useMemo } from 'react';
import AppShell from '@/layouts/AppShell';
import PageHero from '@/components/PageHero';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';

export interface CalendarCell {
    date: string;
    trimp: number | null;
    distance_km: number | null;
    activity_id: number | null;
}

interface KalenderProps {
    cells: ReadonlyArray<CalendarCell>;
    rangeStart: string;
    rangeEnd: string;
    months: number;
}

interface MonthGroup {
    /** YYYY-MM key. */
    key: string;
    /** Human label, e.g. "Mei 2026". */
    label: string;
    /** Day-of-week offset (0 = Senin) for the first day of the month. */
    leadingOffset: number;
    cells: CalendarCell[];
}

const WEEKDAY_LABELS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as const;

const MONTH_LABELS = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
] as const;

export default function Kalender({ cells }: Readonly<KalenderProps>) {
    const months = useMemo<MonthGroup[]>(() => groupByMonth(cells), [cells]);

    return (
        <AppShell>
            <Head title="Kalender" />
            <motion.main
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="w-full px-4 py-6 sm:px-6 sm:py-10"
            >
                <PageHero
                    icon="mdi:calendar-month-outline"
                    title="Kalender"
                    subtitle="Lihat semua hari larimu dalam satu tampilan bulanan. Hari berwarna artinya kamu lari."
                    className="mb-6"
                />

                <IntensityLegend className="mb-6" />

                {months.length === 0 ? (
                    <EmptyState />
                ) : (
                    <div className="space-y-8">
                        {months.map((month) => (
                            <MonthGrid key={month.key} month={month} />
                        ))}
                    </div>
                )}
            </motion.main>
        </AppShell>
    );
}

function MonthGrid({ month }: Readonly<{ month: MonthGroup }>) {
    return (
        <section aria-label={month.label} className="rounded-2xl border border-line bg-surface-elev p-4 shadow-sm sm:p-5">
            <h2 className="text-base font-semibold text-ink">{month.label}</h2>
            <div className="mt-3 grid grid-cols-7 gap-1 text-center text-[10px] font-semibold uppercase tracking-wider text-ink-meta">
                {WEEKDAY_LABELS.map((label) => (
                    <div key={label} className="py-1">
                        {label}
                    </div>
                ))}
            </div>
            <div className="mt-1 grid grid-cols-7 gap-1">
                {Array.from({ length: month.leadingOffset }).map((_, idx) => (
                    <div key={`pad-${idx}`} aria-hidden />
                ))}
                {month.cells.map((cell) => (
                    <DayCell key={cell.date} cell={cell} />
                ))}
            </div>
        </section>
    );
}

function DayCell({ cell }: Readonly<{ cell: CalendarCell }>) {
    const day = Number(cell.date.slice(-2));
    const bucket = intensityBucket(cell.trimp);
    const hasRun = cell.trimp !== null && cell.trimp > 0;
    const tooltip = cellTooltip(cell);

    const baseClass = cn(
        'flex aspect-square flex-col items-center justify-center rounded-md text-xs transition',
        hasRun ? cn('font-semibold text-white shadow-sm', BUCKET_BG[bucket]) : 'bg-surface-sunken/40 text-ink-meta',
    );

    const content = (
        <>
            <span>{day}</span>
            {cell.distance_km !== null && cell.distance_km > 0 && (
                <span className="text-[9px] font-medium tabular-nums opacity-90">{cell.distance_km.toFixed(1)}</span>
            )}
        </>
    );

    if (cell.activity_id !== null) {
        return (
            <Link
                href={`/aktivitas/${cell.activity_id}`}
                className={cn(baseClass, 'hover:ring-2 hover:ring-brand-400 hover:ring-offset-1 hover:ring-offset-surface-elev')}
                title={tooltip}
                aria-label={tooltip}
            >
                {content}
            </Link>
        );
    }

    return (
        <div className={baseClass} title={tooltip} aria-label={tooltip}>
            {content}
        </div>
    );
}

function IntensityLegend({ className }: Readonly<{ className?: string }>) {
    return (
        <div className={cn('flex items-center gap-2 text-xs text-ink-meta', className)}>
            <span>Intensitas:</span>
            <span>ringan</span>
            {([1, 2, 3, 4] as const).map((bucket) => (
                <span key={bucket} className={cn('h-3 w-3 rounded-sm', BUCKET_BG[bucket])} aria-hidden />
            ))}
            <span>berat</span>
        </div>
    );
}

function EmptyState() {
    return (
        <div className="rounded-2xl border border-dashed border-line bg-surface-elev/40 p-10 text-center">
            <p className="text-sm leading-relaxed text-ink">
                Belum ada lari yang tercatat di rentang ini. Sinkronkan aktivitas Strava dulu, ya.
            </p>
        </div>
    );
}

const BUCKET_BG: Record<1 | 2 | 3 | 4, string> = {
    1: 'bg-brand-300',
    2: 'bg-brand-500',
    3: 'bg-accent-500',
    4: 'bg-mood-cooked',
};

function intensityBucket(trimp: number | null): 1 | 2 | 3 | 4 {
    if (trimp === null || trimp < 30) return 1;
    if (trimp < 80) return 2;
    if (trimp < 150) return 3;
    return 4;
}

function cellTooltip(cell: CalendarCell): string {
    if (cell.trimp === null || cell.trimp <= 0) return cell.date;
    const km = cell.distance_km !== null ? `${cell.distance_km.toFixed(2)} km · ` : '';
    return `${cell.date} · ${km}TRIMP ${cell.trimp}`;
}

function groupByMonth(cells: ReadonlyArray<CalendarCell>): MonthGroup[] {
    if (cells.length === 0) return [];

    const groups = new Map<string, MonthGroup>();
    const ordered: string[] = [];

    for (const cell of cells) {
        const monthKey = cell.date.slice(0, 7); // YYYY-MM
        let group = groups.get(monthKey);
        if (!group) {
            const firstDate = parseDate(`${monthKey}-01`);
            group = {
                key: monthKey,
                label: `${MONTH_LABELS[firstDate.getMonth()]} ${firstDate.getFullYear()}`,
                leadingOffset: (firstDate.getDay() + 6) % 7, // Monday = 0
                cells: [],
            };
            groups.set(monthKey, group);
            ordered.push(monthKey);
        }
        group.cells.push(cell);
    }

    // Most recent month last so the user reads top → bottom = old → new and
    // can naturally scroll to today.
    return ordered.map((k) => groups.get(k)!);
}

function parseDate(iso: string): Date {
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d);
}
