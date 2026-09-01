import type { ActivityDetail, StreamSummary } from '@/types/inertia';

import EmptyPanel from '@/components/ui/EmptyPanel';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import { cn } from '@/lib/cn';

// Mirrors the "hot run" threshold used across the backend narration (e.g.
// RunCardFactory, Story/Temari) so the frontend softens the same runs the
// narrators already treat as heat-affected.
const HOT_TEMP_C = 31;
const DECOUPLING_HIGH = 8;

/** The bar's span, wide enough to hold a resting and a maximal reading. */
const HR_SCALE_MIN = 100;
const HR_SCALE_MAX = 190;

function hrScalePct(bpm: number): number {
    const clamped = Math.min(Math.max(bpm, HR_SCALE_MIN), HR_SCALE_MAX);
    return ((clamped - HR_SCALE_MIN) / (HR_SCALE_MAX - HR_SCALE_MIN)) * 100;
}

/**
 * Where the decoupling marker sits on the leaf→citrus→ember gradient. 0% drift
 * is "held steady" at the green end; the scale runs to twice the high-drift
 * threshold, so a genuinely bad run pins the red end rather than falling off it.
 */
function decouplingPct(value: number): number {
    return (
        Math.min(Math.max(value, 0), DECOUPLING_HIGH * 2) *
        (100 / (DECOUPLING_HIGH * 2))
    );
}

function decouplingNote(
    decoupling: number,
    detail: ActivityDetail,
): { text: string; warn: boolean } {
    // A hot run drifts HR up for a physiological reason (body works harder to
    // shed heat), not a fitness regression, so it doesn't earn the warn tone.
    // Only applies to a positive drift, mirroring the backend's rule
    // (RuleBasedInsightBuilder decoupling > DECOUPLING_HIGH) — a large negative
    // decoupling isn't HR drift at all, so heat can't explain it away.
    const wasHot =
        detail.weather_temp_c != null && detail.weather_temp_c >= HOT_TEMP_C;
    const high = Math.abs(decoupling) > DECOUPLING_HIGH;

    if (decoupling > DECOUPLING_HIGH && wasHot) {
        return {
            text: `normal, it was ${Math.round(detail.weather_temp_c as number)}°C out`,
            warn: false,
        };
    }

    return {
        text: high
            ? 'breathing drifted in the second half'
            : 'breathing held steady to the end',
        warn: high,
    };
}

interface VitalTile {
    label: string;
    icon: string;
    value: string;
}

/**
 * The run's physiology, as the prototype's `VitalsCard` arranges it: heart rate
 * on a scale bar with its max marked, then cadence / steepest grade / flat pace
 * as three tiles, then decoupling as a position on a leaf→ember gradient.
 * Anything the run did not record is simply absent.
 */
export default function VitalsCard({
    detail,
    summary,
    className,
}: Readonly<{
    detail: ActivityDetail;
    summary: StreamSummary;
    className?: string;
}>) {
    const avgHr =
        detail.average_heartrate != null
            ? Math.round(detail.average_heartrate)
            : null;
    const maxHr = detail.max_heartrate ?? null;

    const tiles: VitalTile[] = [];
    if (detail.average_cadence != null) {
        tiles.push({
            label: 'spm avg',
            icon: 'mdi:shoe-print',
            value: `${Math.round(detail.average_cadence * 2)}`,
        });
    }
    // stream_summary is an untyped DB JSON blob; a corrupt or legacy row can
    // carry an unusable reading, which must not render as "NaN%". Only a run
    // that actually climbed shows a grade, so a flat GPS run doesn't show 0%.
    const maxGrade = Number(summary.max_grade_pct);
    if (
        summary.max_grade_pct != null &&
        Number.isFinite(maxGrade) &&
        maxGrade >= 3
    ) {
        tiles.push({
            label: 'steepest grade',
            icon: 'mdi:terrain',
            value: `${maxGrade}%`,
        });
        if (summary.gap_pace != null) {
            tiles.push({
                label: 'flat pace /km',
                icon: 'mdi:scale-balance',
                value: summary.gap_pace,
            });
        }
    }

    const decouplingRaw = Number(summary.decoupling_pct);
    const decoupling =
        summary.decoupling_pct != null && Number.isFinite(decouplingRaw)
            ? decouplingRaw
            : null;

    if (avgHr === null && tiles.length === 0 && decoupling === null) {
        return (
            <EmptyPanel
                as="section"
                title="Technical detail hasn't been read yet."
                className={className}
            />
        );
    }

    return (
        <Card as="section" padding="hero" className={className}>
            <Eyebrow token="micro" tone="ink-2" className="mb-3.5">
                Vitals
            </Eyebrow>

            {avgHr !== null && (
                <>
                    <Eyebrow token="micro" tone="ink-3" className="mb-1.5">
                        Heart rate
                    </Eyebrow>
                    <div className="flex items-end justify-between gap-3">
                        <div>
                            <b className="font-mono text-stat-sm font-bold tabular-nums tracking-[-0.02em] text-foreground">
                                {avgHr}
                            </b>
                            <span className="ml-1 text-label-micro text-text-2">
                                avg bpm
                            </span>
                        </div>
                        {maxHr !== null && (
                            <div>
                                <b className="font-mono text-base font-bold tabular-nums text-foreground">
                                    {maxHr}
                                </b>
                                <span className="ml-1 text-label-micro text-text-3">
                                    max
                                </span>
                            </div>
                        )}
                    </div>
                    <div className="relative mt-2.5 h-2 rounded-full bg-muted">
                        <div
                            className="h-full rounded-full bg-icon-accent"
                            style={{ width: `${hrScalePct(avgHr)}%` }}
                        />
                        {maxHr !== null && (
                            <div
                                aria-hidden
                                className="absolute top-1/2 h-3.5 w-0.5 -translate-y-1/2 rounded-full bg-foreground"
                                style={{ left: `${hrScalePct(maxHr)}%` }}
                            />
                        )}
                    </div>
                </>
            )}

            {tiles.length > 0 && (
                <div
                    className={cn(
                        'grid grid-cols-3 gap-2',
                        avgHr !== null && 'mt-4',
                    )}
                >
                    {tiles.map((tile) => (
                        <div
                            key={tile.label}
                            className="rounded-sm bg-muted p-2.5 text-center"
                        >
                            <Icon
                                icon={tile.icon}
                                width={16}
                                height={16}
                                aria-hidden
                                className="mx-auto text-icon-accent"
                            />
                            <b className="mt-1.5 block font-mono text-sm font-bold tabular-nums text-foreground">
                                {tile.value}
                            </b>
                            <span className="block font-sans text-xs text-text-2">
                                {tile.label}
                            </span>
                        </div>
                    ))}
                </div>
            )}

            {decoupling !== null && (
                <Decoupling value={decoupling} detail={detail} />
            )}
        </Card>
    );
}

function Decoupling({
    value,
    detail,
}: Readonly<{ value: number; detail: ActivityDetail }>) {
    const note = decouplingNote(value, detail);

    return (
        <div className="mt-3.5">
            <div className="flex items-center justify-between gap-3">
                <Eyebrow token="micro" tone="ink-3" as="span">
                    Decoupling
                </Eyebrow>
                <b
                    className={cn(
                        'font-mono text-sm font-bold tabular-nums',
                        note.warn ? 'text-ember-ink' : 'text-icon-accent',
                    )}
                >
                    {value >= 0 ? '+' : ''}
                    {value.toFixed(1)}%
                </b>
            </div>
            <div className="relative mt-2 h-1.5 rounded-full bg-[linear-gradient(90deg,var(--color-leaf),var(--color-citrus),var(--color-ember))]">
                <div
                    aria-hidden
                    className="absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-card bg-foreground shadow-e1"
                    style={{ left: `${decouplingPct(value)}%` }}
                />
            </div>
            <p className="mt-2 font-sans text-xs text-text-2">{note.text}</p>
        </div>
    );
}
