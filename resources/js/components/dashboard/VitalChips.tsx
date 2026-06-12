import MetricExplainer from '@/components/MetricExplainer';
import SectionLabel from '@/components/ui/SectionLabel';
import { cn } from '@/lib/cn';
import { formStatusLabel } from '@/lib/formStatus';
import type { MetricKey } from '@/lib/metricGlossary';
import { formatSignedForm } from '@/pages/HariIni/helpers';
import type { BriefingResult, TrainingLoad } from '@/types/inertia';

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
            />
            <VitalChip
                label="Kesiapan"
                value={load ? formatSignedForm(load.form) : '—'}
                sub={load ? formStatusLabel(load.form_status) : ''}
                tone="leaf"
                explainerKey="form"
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

function VitalChip({
    label,
    value,
    sub,
    tone,
    explainerKey,
}: Readonly<{ label: string; value: string; sub: string; tone: 'horizon' | 'leaf' | 'ink'; explainerKey?: MetricKey }>) {
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
            <div className={cn('min-w-0 font-sans text-stat-fluid font-bold tabular-nums tracking-[-0.02em]', valueClass)}>
                {value}
            </div>
            {sub !== '' && <div className="mt-1 font-display text-xs italic text-ink-3">{sub}</div>}
        </div>
    );
}
