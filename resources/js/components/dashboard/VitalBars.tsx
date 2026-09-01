import type { MetricKey } from '@/lib/metricGlossary';
import type { BriefingResult, TrainingLoad } from '@/types/inertia';

import MetricExplainer from '@/components/MetricExplainer';
import { cn } from '@/lib/cn';
import { formStatusLabel } from '@/lib/formStatus';
import { formatSignedForm } from '@/pages/Home/helpers';

// Form (= ctl − atl) is unbounded, but formStatus() buckets fresh/optimal/fatigued/
// overreaching within roughly ±40 at typical CTL, so that's the rail's clamp range.
const FORM_RANGE = 40;

// Recovery rails at 72h: BriefingComposer switches its label from hours to days
// at the same threshold, and roughly three days is where a hard session stops
// costing you. Past it the gauge just reads full.
const RECOVERY_HOURS_FULL = 72;

// A one-line gloss per vibe, keyed by its label (mirrors Vibe::LABELS).
// Sits on the sub-line so the row says something ("feeling light") instead
// of just restating the word.
const VIBE_SUB: Record<string, string> = {
    Bouncy: 'feeling light',
    Steady: 'holding rhythm',
    'Worn Down': 'energy dipping',
    Cooked: 'wiped out, rest up',
    Fresh: 'ready to go',
    'Stretched Thin': 'close to the limit',
    Pumped: 'on fire',
    Hibernating: "haven't run in a while",
};

type VitalTone = 'good' | 'watch';

function pct(value: number, min: number, max: number): number {
    const clamped = Math.min(Math.max(value, min), max);
    return ((clamped - min) / (max - min)) * 100;
}

function VitalRow({
    label,
    value,
    fill,
    sub,
    tone,
    explainerKey,
}: Readonly<{
    label: string;
    value: string;
    fill: number;
    sub: string;
    tone: VitalTone;
    explainerKey: MetricKey;
}>) {
    return (
        <div className="flex items-center gap-3">
            <div className="w-19 flex-none">
                <span className="flex items-center gap-1 font-mono text-[9px] uppercase tracking-[0.05em] text-foreground">
                    {label}
                    <MetricExplainer metricKey={explainerKey} size="xs" />
                </span>
                <b
                    className={cn(
                        'block text-[13px] font-extrabold',
                        tone === 'watch'
                            ? 'text-citrus-ink'
                            : 'text-foreground',
                    )}
                >
                    {value}
                </b>
            </div>
            <div className="flex-1">
                <div
                    className="h-0.5 overflow-hidden rounded-full bg-border"
                    role="meter"
                    aria-label={label}
                    aria-valuenow={Math.round(fill)}
                    aria-valuemin={0}
                    aria-valuemax={100}
                >
                    <div
                        className={cn(
                            'h-full',
                            tone === 'watch' ? 'bg-citrus' : 'bg-horizon',
                        )}
                        style={{ width: `${fill}%` }}
                    />
                </div>
                {sub !== '' && (
                    <span className="mt-1 block text-[9.5px] italic text-foreground">
                        {sub}
                    </span>
                )}
            </div>
        </div>
    );
}

/**
 * The prototype's three vital bars inside Today's "this week's stats"
 * disclosure: vibe, readiness and recovery, each a label + value beside a
 * bounded rail and a one-line gloss. Replaces the 3-up gauge tiles the
 * shipped dashboard drew in this slot.
 */
export default function VitalBars({
    briefing,
    load,
}: Readonly<{
    briefing: BriefingResult;
    load: TrainingLoad | null;
}>) {
    const form = load?.form ?? null;
    const readinessWatch =
        load !== null &&
        (load.form_status === 'fatigued' ||
            load.form_status === 'overreaching');

    return (
        <div className="flex flex-col gap-2.5">
            <VitalRow
                label="Vibe"
                value={briefing.vibeLabel}
                fill={form === null ? 0 : pct(Math.abs(form), 0, FORM_RANGE)}
                sub={VIBE_SUB[briefing.vibeLabel] ?? ''}
                tone="good"
                explainerKey="vibe_vs_mood"
            />
            <VitalRow
                label="Readiness"
                value={form === null ? '—' : formatSignedForm(form)}
                fill={form === null ? 0 : pct(form, -FORM_RANGE, FORM_RANGE)}
                sub={load === null ? '' : formStatusLabel(load.form_status)}
                tone={readinessWatch ? 'watch' : 'good'}
                explainerKey="form"
            />
            <VitalRow
                label="Recovery"
                value={
                    briefing.recoveryHoursLabel ??
                    briefing.streakLabel ??
                    briefing.recoveryLabel
                }
                fill={
                    briefing.recoveryHours === null
                        ? 0
                        : pct(briefing.recoveryHours, 0, RECOVERY_HOURS_FULL)
                }
                sub="since last run"
                tone={briefing.recoveryTone === 'positive' ? 'good' : 'watch'}
                explainerKey="recovery"
            />
        </div>
    );
}
