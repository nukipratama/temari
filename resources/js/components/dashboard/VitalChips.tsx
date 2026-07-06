import type { ReactNode } from 'react';
import MetricExplainer from '@/components/MetricExplainer';
import SectionLabel from '@/components/ui/SectionLabel';
import { cn } from '@/lib/cn';
import { formStatusLabel } from '@/lib/formStatus';
import type { MetricKey } from '@/lib/metricGlossary';
import { formatSignedForm } from '@/pages/HariIni/helpers';
import type { BriefingResult, RecoveryTone, TrainingLoad } from '@/types/inertia';

// Form (= ctl − atl) is unbounded, but formStatus() buckets fresh/optimal/fatigued/
// overreaching within roughly ±40 at typical CTL, so that's the rail's clamp range.
const FORM_RANGE = 40;

export default function VitalChips({ briefing, load }: Readonly<{ briefing: BriefingResult; load: TrainingLoad | null }>) {
    // Vibe leads with the qualitative emoji + its label side by side: there's no
    // numeric vibe score, and the old absolute-form proxy duplicated Kesiapan's
    // number whenever form was positive (|form| == form). The horizon gauge still
    // shows form intensity; the sub-line becomes an invisible spacer (like
    // RecoveryRail below) so its gauge still aligns with its two siblings'.
    const vibeValue = (
        <span className="inline-flex items-baseline gap-2">
            <span className="text-[1.6rem] leading-none">{briefing.vibeEmoji}</span>
            <span className="font-display text-xl font-semibold not-italic text-horizon-deep">{briefing.vibeLabel}</span>
        </span>
    );

    return (
        <div className="grid h-full grid-cols-3 gap-3">
            <VitalChip
                label="Vibe"
                value={vibeValue}
                sub={' '}
                tone="horizon"
                explainerKey="vibe_vs_mood"
                gauge={load?.form != null ? { label: 'Vibe', value: Math.abs(load.form), min: 0, max: FORM_RANGE, tone: 'horizon', anchors: ['0', String(FORM_RANGE)] } : undefined}
            />
            <VitalChip
                label="Kesiapan"
                value={load ? formatSignedForm(load.form) : '—'}
                sub={load ? formStatusLabel(load.form_status) : ''}
                tone="leaf"
                explainerKey="form"
                gauge={load?.form != null ? { label: 'Kesiapan', value: load.form, min: -FORM_RANGE, max: FORM_RANGE, tone: 'leaf', bipolar: true, anchors: [`−${FORM_RANGE}`, `+${FORM_RANGE}`] } : undefined}
            />
            <VitalChip
                label="Recovery"
                value={briefing.recoveryHoursLabel ?? briefing.streakLabel ?? briefing.recoveryLabel}
                sub="dari lari terakhir"
                tone="ink"
                recoveryTone={briefing.recoveryTone}
            />
        </div>
    );
}

interface GaugeConfig {
    /** Accessible name for the gauge (the metric label, e.g. "Kesiapan"). */
    label: string;
    value: number;
    min: number;
    max: number;
    tone: 'horizon' | 'leaf';
    bipolar?: boolean;
    anchors: [string, string];
}

/** Thin bounded rail so a raw signed score reads as "where am I in the range" at a glance. */
function VitalGauge({ label, value, min, max, tone, bipolar, anchors }: Readonly<GaugeConfig>) {
    const clamped = Math.min(Math.max(value, min), max);
    const pct = ((clamped - min) / (max - min)) * 100;
    // Bipolar (Kesiapan): fill grows from the zero mark; leaf when positive, ember when negative.
    const leafPolarity = value >= 0 ? 'bg-leaf' : 'bg-ember';
    const fillColor = tone === 'leaf' ? leafPolarity : 'bg-horizon';
    const zeroPct = bipolar ? ((0 - min) / (max - min)) * 100 : 0;
    return (
        <div className="mt-1.5">
            <meter className="sr-only" aria-label={label} value={clamped} min={min} max={max} />
            <div aria-hidden className="relative h-1.5 w-full overflow-hidden rounded-full bg-sky/[0.08]">
                <div
                    className={cn('absolute top-0 h-full rounded-full', fillColor)}
                    style={{ left: `${Math.min(pct, zeroPct)}%`, width: `${Math.abs(pct - zeroPct)}%` }}
                />
                {bipolar && <div className="absolute inset-y-[-1px] w-px bg-ink-3/40" style={{ left: `${zeroPct}%` }} />}
            </div>
            <div className="mt-1 flex justify-between font-mono text-[11px] tabular-nums text-ink-3">
                <span>{anchors[0]}</span>
                <span>{anchors[1]}</span>
            </div>
        </div>
    );
}

// Recovery has no numeric gauge, so a thin tone-coloured rail fills the slot its
// two gauge-bearing siblings occupy, keeping the 3-up row a cohesive family instead
// of two rich chips beside one sparse one.
const RECOVERY_RAIL: Record<RecoveryTone, string> = {
    positive: 'bg-leaf',
    warning: 'bg-citrus',
    alert: 'bg-ember',
    neutral: 'bg-ink-3/40',
};

function RecoveryRail({ tone }: Readonly<{ tone: RecoveryTone }>) {
    return (
        <div className="mt-1.5">
            <div className={cn('h-1.5 w-full rounded-full', RECOVERY_RAIL[tone])} />
            {/* Invisible spacer matching VitalGauge's min/max label row height, so the
                Recovery value stays vertically aligned with its two gauge-bearing
                siblings instead of dropping (the chips are bottom-anchored). */}
            <div aria-hidden className="mt-1 font-mono text-[11px]">&nbsp;</div>
        </div>
    );
}

function VitalChip({
    label,
    value,
    sub,
    tone,
    explainerKey,
    gauge,
    recoveryTone,
}: Readonly<{ label: string; value: ReactNode; sub: string; tone: 'horizon' | 'leaf' | 'ink'; explainerKey?: MetricKey; gauge?: GaugeConfig; recoveryTone?: RecoveryTone }>) {
    // Color the tiny label dot, not the number — keeps the page from feeling
    // like a paint-store sample card while still tagging the metric's family.
    const dotClass = {
        horizon: 'bg-horizon',
        leaf: 'bg-leaf',
        ink: 'bg-ink-3',
    }[tone];
    const valueClass = {
        horizon: 'text-horizon-deep',
        leaf: 'text-leaf',
        ink: 'text-ink',
    }[tone];
    let middleBand: ReactNode = null;
    if (gauge) {
        middleBand = <VitalGauge {...gauge} />;
    } else if (recoveryTone) {
        middleBand = <RecoveryRail tone={recoveryTone} />;
    }
    return (
        <div className="flex h-full flex-col justify-between rounded-xl border border-line bg-surface-card px-3.5 py-4">
            <SectionLabel dot dotClass={dotClass} className="mb-1">
                <span className="inline-flex items-center gap-1.5">
                    {label}
                    {explainerKey && <MetricExplainer metricKey={explainerKey} size="xs" />}
                </span>
            </SectionLabel>
            <div className="mt-auto">
                <div className={cn('min-w-0 font-sans text-stat-fluid font-bold tabular-nums tracking-[-0.02em]', valueClass)}>
                    {value}
                </div>
                {middleBand}
                {sub !== '' && <div className="mt-1 font-display text-xs italic text-ink-3">{sub}</div>}
            </div>
        </div>
    );
}
