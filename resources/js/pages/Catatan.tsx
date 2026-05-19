import { Head } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import { formatIdDate } from '@/lib/pace';
import AppShell from '@/layouts/AppShell';
import DecorativeBlur from '@/components/DecorativeBlur';
import PageHero from '@/components/PageHero';
import SectionHeading from '@/components/SectionHeading';
import { fadeInUp } from '@/lib/motion';
import { ICON_TONE, type Tone } from '@/lib/tones';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import type { AnalysisPayload, FormStatus, WeeklySnapshot } from '@/types/inertia';

interface ExtendedSnapshot extends WeeklySnapshot {
    weekly_trimp: number | null;
    form_status: FormStatus | null;
    recap_analysis?: AnalysisPayload;
}

interface CatatanProps {
    snapshots: ExtendedSnapshot[];
}

const SNAPSHOT_COLUMNS = [
    'Minggu',
    'Volume',
    'Run',
    'TRIMP',
    'CTL',
    'ATL',
    'Form',
    'Status',
    'Catatan Temari',
] as const;

export default function Catatan({ snapshots }: Readonly<CatatanProps>) {
    const latest = snapshots[0] ?? null;
    const prior = snapshots[1] ?? null;

    return (
        <AppShell>
            <Head title="Catatan" />
            <motion.main
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="w-full px-6 py-10"
            >
                <PageHero
                    icon="mdi:notebook-outline"
                    title="Catatan"
                    subtitle="Ringkasan kondisi tubuh + beban mingguan."
                    className="mb-6"
                />

                {latest !== null && <HeroStats latest={latest} prior={prior} />}

                {snapshots.length > 0 && (
                    <section className="mt-10">
                        <SectionHeading
                            icon="mdi:chart-timeline-variant"
                            title="Riwayat Mingguan"
                            subtitle="Beban + fitness 14 minggu terakhir."
                            tone="brand"
                        />
                        <div className="mt-4 overflow-x-auto rounded-2xl border border-line bg-surface-elev shadow-sm">
                            <table className="w-full text-sm tabular-nums">
                                <thead>
                                    <tr className="border-b border-line text-left text-xs text-ink-meta">
                                        {SNAPSHOT_COLUMNS.map((label) => (
                                            <th key={label} className="px-5 py-3 font-semibold">
                                                {label}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {snapshots.map((snap, i) => (
                                        <SnapshotRow key={snap.id} snap={snap} isCurrent={i === 0} />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}
            </motion.main>
        </AppShell>
    );
}

function SnapshotRow({ snap, isCurrent }: Readonly<{ snap: ExtendedSnapshot; isCurrent: boolean }>) {
    const rowClass = isCurrent
        ? 'bg-gradient-to-r from-accent-50/70 via-surface-warm to-surface-elev'
        : rowToneByStatus(snap.form_status);
    return (
        <tr className={cn('border-b border-line last:border-b-0 transition', rowClass)}>
            <td className="px-5 py-3">
                <div className="font-medium text-ink">{weekRangeLabel(snap.week_ending)}</div>
                {isCurrent && (
                    <div className="text-[10px] uppercase tracking-wider text-accent-700">
                        Minggu ini
                    </div>
                )}
            </td>
            <td className="px-5 py-3 text-ink">{fmtKm(snap.distance_km)}</td>
            <td className="px-5 py-3 text-ink">{snap.runs ?? '—'}</td>
            <td className="px-5 py-3 text-ink">{fmtInt(snap.weekly_trimp)}</td>
            <td className="px-5 py-3 font-medium text-ink">{fmtOne(snap.ctl_42d)}</td>
            <td className="px-5 py-3 text-ink-soft">{fmtOne(snap.atl_7d)}</td>
            <td className="px-5 py-3 text-ink-soft">{fmtOne(snap.form)}</td>
            <td className="px-5 py-3">
                <StatusChip status={snap.form_status} />
            </td>
            <td className="px-5 py-3 align-top">
                {snap.recap_analysis && (
                    <AnalysisStatus
                        analysis={snap.recap_analysis}
                        inertiaReloadProps={['snapshots']}
                        size="sm"
                    />
                )}
            </td>
        </tr>
    );
}

interface HeroStatsProps {
    latest: ExtendedSnapshot;
    prior: ExtendedSnapshot | null;
}

function HeroStats({ latest, prior }: Readonly<HeroStatsProps>) {
    return (
        <section className="grid grid-cols-2 gap-2 lg:grid-cols-4">
            <HeroStat
                label="Fitness"
                value={fmtOne(latest.ctl_42d)}
                delta={delta(latest.ctl_42d, prior?.ctl_42d ?? null)}
                hint="CTL · 42 hari"
                icon="mdi:lightning-bolt"
                tone="brand"
            />
            <HeroStat
                label="Fatigue"
                value={fmtOne(latest.atl_7d)}
                delta={delta(latest.atl_7d, prior?.atl_7d ?? null)}
                hint="ATL · 7 hari"
                icon="mdi:battery-low"
                tone="accent"
                invertDelta
            />
            <HeroStat
                label="Form"
                value={fmtOne(latest.form)}
                delta={delta(latest.form, prior?.form ?? null)}
                hint={latest.form_status ?? '—'}
                icon="mdi:scale-balance"
                tone="brand"
            />
            <HeroStat
                label="Volume minggu ini"
                value={fmtKm(latest.distance_km)}
                delta={null}
                hint={latest.runs == null ? null : `${latest.runs} run`}
                icon="mdi:run-fast"
                tone="pop"
            />
        </section>
    );
}

interface HeroStatProps {
    label: string;
    value: string;
    delta: number | null;
    hint?: string | null;
    icon: string;
    tone: Tone;
    /** When true, a positive delta is *bad* (e.g. fatigue rising). */
    invertDelta?: boolean;
}

const HERO_RING_TONE: Record<Tone, string> = {
    brand: 'border-brand-300 bg-gradient-to-br from-brand-50 via-surface-elev to-brand-100/70 shadow-brand-200/40',
    accent: 'border-accent-300 bg-gradient-to-br from-accent-50 via-surface-elev to-accent-100/70 shadow-accent-200/40',
    pop: 'border-pop-300 bg-gradient-to-br from-pop-50 via-surface-elev to-pop-100/70 shadow-pop-200/40',
    neutral: 'border-line bg-surface-sunken/30',
};

const HERO_VALUE_TONE: Record<Tone, string> = {
    brand: 'text-brand-700',
    accent: 'text-accent-700',
    pop: 'text-pop-700',
    neutral: 'text-ink',
};

function HeroStat({ label, value, delta, hint, icon, tone, invertDelta = false }: Readonly<HeroStatProps>) {
    const showHint = hint != null && hint !== '';
    return (
        <div className={cn('relative overflow-hidden rounded-2xl border p-5 shadow-md transition hover:-translate-y-0.5 hover:shadow-lg', HERO_RING_TONE[tone])}>
            <DecorativeBlur intensity="md" className="-right-6 -top-6 h-20 w-20 bg-white/50" />
            <div className="relative flex items-start justify-between">
                <div className="text-xs font-semibold uppercase tracking-wider text-ink-meta">{label}</div>
                <span className={cn('flex h-8 w-8 items-center justify-center rounded-lg shadow-sm ring-1 ring-white/60', ICON_TONE[tone])} aria-hidden>
                    <Icon icon={icon} width={16} height={16} />
                </span>
            </div>
            <div className="relative mt-2 flex items-baseline gap-2">
                <span className={cn('text-3xl font-black tabular-nums', HERO_VALUE_TONE[tone])}>{value}</span>
                {delta !== null && <DeltaChip delta={delta} invert={invertDelta} />}
            </div>
            {showHint && (
                <div className="relative mt-1 text-xs font-medium text-ink-meta capitalize">{hint}</div>
            )}
        </div>
    );
}

interface DeltaChipProps {
    delta: number;
    invert: boolean;
}

function DeltaChip({ delta, invert }: Readonly<DeltaChipProps>) {
    if (Math.abs(delta) < 0.05) {
        return <span className="text-xs font-semibold text-ink-meta">±0</span>;
    }
    const rising = delta > 0;
    const good = invert ? !rising : rising;
    const sign = rising ? '+' : '';
    const color = good ? 'text-brand-600' : 'text-mood-cooked';
    return (
        <span className={cn('text-xs font-semibold tabular-nums', color)}>
            {sign}
            {delta.toFixed(1)}
        </span>
    );
}

const STATUS_CHIP: Record<FormStatus, { label: string; classes: string }> = {
    fresh: { label: 'Fresh', classes: 'bg-brand-100 text-brand-700' },
    optimal: { label: 'Optimal', classes: 'bg-mood-bouncy/15 text-mood-bouncy' },
    fatigued: { label: 'Fatigued', classes: 'bg-mood-glow/20 text-pop-700' },
    overreaching: { label: 'Overreaching', classes: 'bg-mood-cooked/15 text-mood-cooked' },
};

function StatusChip({ status }: Readonly<{ status: FormStatus | null }>) {
    if (status === null) {
        return <span className="text-xs text-ink-meta">—</span>;
    }
    const { label, classes } = STATUS_CHIP[status];
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                classes,
            )}
        >
            {label}
        </span>
    );
}

const ROW_TONE: Record<FormStatus, string> = {
    fresh: 'hover:bg-brand-50/60',
    optimal: 'hover:bg-mood-bouncy/5',
    fatigued: 'bg-pop-50/30 hover:bg-pop-50/60',
    overreaching: 'bg-mood-cooked/5 hover:bg-mood-cooked/10',
};

function rowToneByStatus(status: FormStatus | null): string {
    return status === null ? 'hover:bg-surface-sunken/40' : ROW_TONE[status];
}

function fmtOne(n: number | null): string {
    return n == null ? '—' : n.toFixed(1);
}

function fmtKm(km: number | null): string {
    return km == null ? '—' : `${km.toFixed(1)} km`;
}

function fmtInt(n: number | null): string {
    return n == null ? '—' : Math.round(n).toString();
}

function delta(a: number | null, b: number | null): number | null {
    if (a === null || b === null) return null;
    return a - b;
}

function weekRangeLabel(weekEndingIso: string | null): string {
    if (weekEndingIso === null) return '—';
    const sunday = new Date(weekEndingIso);
    if (Number.isNaN(sunday.getTime())) return '—';
    const monday = new Date(sunday);
    monday.setDate(monday.getDate() - 6);
    return `${formatIdDate(monday.toISOString(), 'long')} — ${formatIdDate(sunday.toISOString(), 'long')}`;
}
