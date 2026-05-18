import { Head } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import { formatIdDate } from '@/lib/pace';
import AppShell from '@/layouts/AppShell';
import DecorativeBlur from '@/components/DecorativeBlur';
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
                <header className="relative mb-6 overflow-hidden rounded-3xl border border-line bg-gradient-to-br from-brand-50 via-surface-warm to-accent-50 p-6 shadow-md">
                    <DecorativeBlur className="-right-16 -top-16 h-48 w-48 bg-pop-200/50" />
                    <DecorativeBlur className="-bottom-12 -left-10 h-40 w-40 bg-brand-200/40" />
                    <div className="relative flex items-center gap-3">
                        <span aria-hidden className="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-500 text-white shadow-md ring-2 ring-white">
                            <Icon icon="mdi:notebook-outline" width={24} height={24} />
                        </span>
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight text-ink">Catatan</h1>
                            <p className="mt-1 text-sm leading-relaxed text-ink-soft">
                                Ringkasan kondisi tubuh + beban mingguan.
                            </p>
                        </div>
                    </div>
                </header>

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
                                        <th className="px-5 py-3 font-semibold">Minggu</th>
                                        <th className="px-5 py-3 font-semibold">Volume</th>
                                        <th className="px-5 py-3 font-semibold">Run</th>
                                        <th className="px-5 py-3 font-semibold">TRIMP</th>
                                        <th className="px-5 py-3 font-semibold">CTL</th>
                                        <th className="px-5 py-3 font-semibold">ATL</th>
                                        <th className="px-5 py-3 font-semibold">Form</th>
                                        <th className="px-5 py-3 font-semibold">Status</th>
                                        <th className="px-5 py-3 font-semibold">Catatan Temari</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {snapshots.map((snap, i) => (
                                        <tr
                                            key={snap.id}
                                            className={cn(
                                                'border-b border-line last:border-b-0 transition',
                                                i === 0 && 'bg-gradient-to-r from-accent-50/70 via-surface-warm to-surface-elev',
                                                i !== 0 && rowToneByStatus(snap.form_status),
                                            )}
                                        >
                                            <td className="px-5 py-3">
                                                <div className="font-medium text-ink">
                                                    {weekRangeLabel(snap.week_ending)}
                                                </div>
                                                {i === 0 && (
                                                    <div className="text-[10px] uppercase tracking-wider text-accent-700">
                                                        Minggu ini
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-5 py-3 text-ink">
                                                {snap.distance_km != null ? `${snap.distance_km.toFixed(1)} km` : '—'}
                                            </td>
                                            <td className="px-5 py-3 text-ink">{snap.runs ?? '—'}</td>
                                            <td className="px-5 py-3 text-ink">
                                                {snap.weekly_trimp != null ? Math.round(snap.weekly_trimp) : '—'}
                                            </td>
                                            <td className="px-5 py-3 font-medium text-ink">
                                                {snap.ctl_42d != null ? snap.ctl_42d.toFixed(1) : '—'}
                                            </td>
                                            <td className="px-5 py-3 text-ink-soft">
                                                {snap.atl_7d != null ? snap.atl_7d.toFixed(1) : '—'}
                                            </td>
                                            <td className="px-5 py-3 text-ink-soft">
                                                {snap.form != null ? snap.form.toFixed(1) : '—'}
                                            </td>
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

interface HeroStatsProps {
    latest: ExtendedSnapshot;
    prior: ExtendedSnapshot | null;
}

function HeroStats({ latest, prior }: Readonly<HeroStatsProps>) {
    return (
        <section className="grid grid-cols-2 gap-2 lg:grid-cols-4">
            <HeroStat
                label="Fitness"
                value={fmt(latest.ctl_42d)}
                delta={delta(latest.ctl_42d, prior?.ctl_42d ?? null)}
                hint="CTL · 42 hari"
                icon="mdi:lightning-bolt"
                tone="brand"
            />
            <HeroStat
                label="Fatigue"
                value={fmt(latest.atl_7d)}
                delta={delta(latest.atl_7d, prior?.atl_7d ?? null)}
                hint="ATL · 7 hari"
                icon="mdi:battery-low"
                tone="accent"
                invertDelta
            />
            <HeroStat
                label="Form"
                value={fmt(latest.form)}
                delta={delta(latest.form, prior?.form ?? null)}
                hint={latest.form_status ?? '—'}
                icon="mdi:scale-balance"
                tone="brand"
            />
            <HeroStat
                label="Volume minggu ini"
                value={latest.distance_km != null ? `${latest.distance_km.toFixed(1)} km` : '—'}
                delta={null}
                hint={latest.runs != null ? `${latest.runs} run` : null}
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
            {hint !== null && hint !== undefined && hint !== '' && (
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

function StatusChip({ status }: Readonly<{ status: FormStatus | null }>) {
    if (status === null) {
        return <span className="text-xs text-ink-meta">—</span>;
    }
    const { label, classes } = statusChipDef(status);
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

function rowToneByStatus(status: FormStatus | null): string {
    switch (status) {
        case 'fresh':
            return 'hover:bg-brand-50/60';
        case 'optimal':
            return 'hover:bg-mood-bouncy/5';
        case 'fatigued':
            return 'bg-pop-50/30 hover:bg-pop-50/60';
        case 'overreaching':
            return 'bg-mood-cooked/5 hover:bg-mood-cooked/10';
        default:
            return 'hover:bg-surface-sunken/40';
    }
}

function statusChipDef(status: FormStatus): { label: string; classes: string } {
    switch (status) {
        case 'fresh':
            return { label: 'Fresh', classes: 'bg-brand-100 text-brand-700' };
        case 'optimal':
            return { label: 'Optimal', classes: 'bg-mood-bouncy/15 text-mood-bouncy' };
        case 'fatigued':
            return { label: 'Fatigued', classes: 'bg-mood-glow/20 text-pop-700' };
        case 'overreaching':
            return { label: 'Overreaching', classes: 'bg-mood-cooked/15 text-mood-cooked' };
    }
}

function fmt(n: number | null): string {
    return n != null ? n.toFixed(1) : '—';
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
