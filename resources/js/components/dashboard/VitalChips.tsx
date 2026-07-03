import MetricExplainer from '@/components/MetricExplainer';
import SectionLabel from '@/components/ui/SectionLabel';
import { cn } from '@/lib/cn';
import { formStatusLabel } from '@/lib/formStatus';
import type { MetricKey } from '@/lib/metricGlossary';
import { formatSignedForm } from '@/pages/HariIni/helpers';
import type { BriefingResult, TrainingLoad } from '@/types/inertia';

// Form (= ctl − atl) is unbounded, but formStatus() buckets fresh/optimal/fatigued/
// overreaching within roughly ±40 at typical CTL, so that's the rail's clamp range.
const FORM_RANGE = 40;

export default function VitalChips({ briefing, load }: Readonly<{ briefing: BriefingResult; load: TrainingLoad | null }>) {
    // Vibe primary value: use the absolute form score as a numeric proxy
    // (no dedicated numeric vibe score in the data model). Qualitative label
    // moves to the sub-line.
    const vibeValue = load?.form != null ? Math.abs(load.form).toFixed(1) : briefing.vibeLabel;
    const vibeSub = briefing.vibeLabel.toLowerCase();

    return (
        <div className="grid h-full grid-cols-3 gap-3">
            <VitalChip
                label="Vibe"
                value={vibeValue}
                sub={vibeSub}
                tone="horizon"
                explainerKey="vibe_vs_mood"
                gauge={load?.form != null ? { value: Math.abs(load.form), min: 0, max: FORM_RANGE, tone: 'horizon', anchors: ['0', String(FORM_RANGE)] } : undefined}
            />
            <VitalChip
                label="Kesiapan"
                value={load ? formatSignedForm(load.form) : '—'}
                sub={load ? formStatusLabel(load.form_status) : ''}
                tone="leaf"
                explainerKey="form"
                gauge={load?.form != null ? { value: load.form, min: -FORM_RANGE, max: FORM_RANGE, tone: 'leaf', bipolar: true, anchors: [`−${FORM_RANGE}`, `+${FORM_RANGE}`] } : undefined}
            />
            <VitalChip
                label="Recovery"
                value={briefing.recoveryHoursLabel ?? briefing.streakLabel ?? briefing.recoveryLabel}
                sub="dari lari terakhir"
                tone="ink"
            />
        </div>
    );
}

interface GaugeConfig {
    value: number;
    min: number;
    max: number;
    tone: 'horizon' | 'leaf';
    bipolar?: boolean;
    anchors: [string, string];
}

/** Thin bounded rail so a raw signed score reads as "where am I in the range" at a glance. */
function VitalGauge({ value, min, max, tone, bipolar, anchors }: Readonly<GaugeConfig>) {
    const clamped = Math.min(Math.max(value, min), max);
    const pct = ((clamped - min) / (max - min)) * 100;
    // Bipolar (Kesiapan): fill grows from the zero mark; leaf when positive, ember when negative.
    const leafPolarity = value >= 0 ? 'bg-leaf' : 'bg-ember';
    const fillColor = tone === 'leaf' ? leafPolarity : 'bg-horizon';
    const zeroPct = bipolar ? ((0 - min) / (max - min)) * 100 : 0;
    return (
        <div className="mt-1.5">
            <div className="relative h-1.5 w-full overflow-hidden rounded-full bg-sky/[0.08]">
                <div
                    className={cn('absolute top-0 h-full rounded-full', fillColor)}
                    style={{ left: `${Math.min(pct, zeroPct)}%`, width: `${Math.abs(pct - zeroPct)}%` }}
                />
                {bipolar && <div className="absolute inset-y-[-1px] w-px bg-ink-3/40" style={{ left: `${zeroPct}%` }} />}
            </div>
            <div className="mt-1 flex justify-between font-mono text-[9px] tabular-nums text-ink-3">
                <span>{anchors[0]}</span>
                <span>{anchors[1]}</span>
            </div>
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
}: Readonly<{ label: string; value: string; sub: string; tone: 'horizon' | 'leaf' | 'ink'; explainerKey?: MetricKey; gauge?: GaugeConfig }>) {
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
                {gauge && <VitalGauge {...gauge} />}
                {sub !== '' && <div className="mt-1 font-display text-xs italic text-ink-3">{sub}</div>}
            </div>
        </div>
    );
}
