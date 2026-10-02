import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

import type { Effort } from '@/types/inertia';

import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { EFFORT_STRIPE_CLASS } from '@/lib/effort';
import { formatDuration } from '@/lib/pace';
import { activityUrl } from '@/lib/routes';

type Relation = 'faster' | 'slower' | 'same' | 'higher' | 'lower';

export interface PastYouDuelGap {
    km: number;
    gap_sec: number;
    label: string;
}

export interface PastYouDuel {
    gaps: PastYouDuelGap[];
    net_gap_sec: number;
    net_label: string;
    compared_km: number;
    footnote: string | null;
}

export interface PastYouMatch {
    days_ago: number;
    pace: { seconds_per_km: number; relation: 'faster' | 'slower' | 'same' };
    time: { seconds: number; relation: 'faster' | 'slower' | 'same' };
    hr: { bpm: number; relation: 'higher' | 'lower' | 'same' } | null;
    direction: 'better' | 'worse' | 'flat';
    past_km: number;
    past_activity_id: number;
    past_name: string | null;
    /** The viewed run's own effort — this row's leading-edge stripe, per MASTER.md. */
    effort: Effort;
    /** UI-only km-by-km duel; null until both runs' splits are hydrated. */
    duel: PastYouDuel | null;
}

/** Widest gap a bar visually maxes out at, so one bad km doesn't blow the scale for the rest. */
const GAP_BAR_CAP_SEC = 30;

function gapBarPct(gapSec: number): number {
    return (Math.min(Math.abs(gapSec), GAP_BAR_CAP_SEC) / GAP_BAR_CAP_SEC) * 50;
}

function gapToneClass(gapSec: number): string {
    if (gapSec === 0) {
        return 'text-text-2';
    }

    return gapSec < 0 ? 'text-leaf-ink' : 'text-ember-ink';
}

/** `same` reads as neutral; otherwise `betterWhen` is the relation that tones green. */
function relationTone(relation: Relation, betterWhen: Relation): string {
    if (relation === 'same') {
        return 'text-text-2';
    }

    return relation === betterWhen ? 'text-leaf-ink' : 'text-citrus-ink';
}

/**
 * "You vs past you" — the claim the whole app is built on: its own lane
 * section directly under the hero, leading with the pace delta and linking to
 * the run it is measured against. No match means no section, not an empty
 * state.
 *
 * The wording is built entirely from the `relation` words
 * {@see PastYouMatcher::findMatchContext} already computes (pace banded against
 * noise, heart rate named as the rounded bpm shows it) — this component never
 * re-derives a direction from a raw sign.
 */
export default function PastYouCard({
    match,
    className,
}: Readonly<{ match: PastYouMatch | null; className?: string }>) {
    if (match === null) {
        return null;
    }

    const { pace, hr, time } = match;
    const evenPace = pace.relation === 'same';

    return (
        <section
            className={cn('pl-3', EFFORT_STRIPE_CLASS[match.effort], className)}
        >
            <Eyebrow token="small" tone="ink-2">
                You vs past you
            </Eyebrow>
            <p className="mt-1.5 flex flex-wrap items-baseline gap-x-2 font-mono font-bold leading-tight tabular-nums text-icon-accent">
                {evenPace ? (
                    <span className="text-stat-sm text-foreground">
                        Dead even
                    </span>
                ) : (
                    <>
                        <span className="text-stat-sm">
                            {Math.round(pace.seconds_per_km)}
                        </span>
                        <span className="font-sans text-quote-sm font-semibold text-text-2">
                            sec/km {pace.relation}
                        </span>
                    </>
                )}
            </p>
            <p className="mt-1 font-sans text-xs leading-relaxed text-text-2">
                than the same {match.past_km.toFixed(1)} km, {match.days_ago}{' '}
                days ago
                {match.past_name != null && ` · ${match.past_name}`}
            </p>

            <Link
                href={activityUrl({ activity_id: match.past_activity_id })}
                className="focus-ring hit-area mt-2 inline-flex items-center gap-1 rounded-xs font-sans text-xs font-bold text-icon-accent"
            >
                View that run
                <Icon icon={ArrowRight} width={12} height={12} aria-hidden />
            </Link>

            <dl className="mt-4 grid grid-cols-2 gap-3">
                {hr !== null && (
                    <Delta
                        label="Heart rate"
                        value={`${Math.round(hr.bpm)} bpm`}
                        suffix={
                            hr.relation === 'same' ? 'the same' : hr.relation
                        }
                        toneClass={relationTone(hr.relation, 'lower')}
                    />
                )}
                {time.relation !== 'same' && (
                    <Delta
                        label="Over the distance"
                        value={formatDuration(Math.round(time.seconds))}
                        suffix={
                            time.relation === 'faster' ? 'quicker' : 'slower'
                        }
                        toneClass={relationTone(time.relation, 'faster')}
                    />
                )}
            </dl>

            {match.duel !== null && <DuelSection duel={match.duel} />}
        </section>
    );
}

function DuelSection({ duel }: Readonly<{ duel: PastYouDuel }>) {
    return (
        <div className="mt-4 border-t border-dashed border-border pt-3">
            <Eyebrow token="small" tone="ink-2">
                Km by km
            </Eyebrow>
            <ul className="mt-2 space-y-1.5">
                {duel.gaps.map((gap) => (
                    <li key={gap.km} className="flex items-center gap-2">
                        <span className="w-4 shrink-0 font-mono text-label-micro text-text-3">
                            {gap.km}
                        </span>
                        <div className="relative h-2 flex-1">
                            <div
                                className="absolute inset-y-0 left-1/2 w-px bg-border-strong"
                                aria-hidden
                            />
                            {gap.gap_sec < 0 && (
                                <div
                                    className="absolute inset-y-0 left-1/2 rounded-r-sm bg-leaf"
                                    style={{
                                        width: `${gapBarPct(gap.gap_sec)}%`,
                                    }}
                                    aria-hidden
                                />
                            )}
                            {gap.gap_sec > 0 && (
                                <div
                                    className="absolute inset-y-0 right-1/2 rounded-l-sm bg-ember"
                                    style={{
                                        width: `${gapBarPct(gap.gap_sec)}%`,
                                    }}
                                    aria-hidden
                                />
                            )}
                        </div>
                        <span
                            className={cn(
                                'w-12 shrink-0 text-right font-mono text-xs font-bold tabular-nums',
                                gapToneClass(gap.gap_sec),
                            )}
                        >
                            {gap.label}
                        </span>
                    </li>
                ))}
            </ul>
            <p className="mt-2 flex items-center justify-between">
                <span className="font-sans text-label-micro text-text-3">
                    Net over {duel.compared_km} km
                </span>
                <span
                    className={cn(
                        'font-mono text-xs font-bold tabular-nums',
                        gapToneClass(duel.net_gap_sec),
                    )}
                >
                    {duel.net_label}
                </span>
            </p>
            {duel.footnote !== null && (
                <p className="mt-1 font-sans text-label-micro text-text-3">
                    {duel.footnote}
                </p>
            )}
        </div>
    );
}

function Delta({
    label,
    value,
    suffix,
    toneClass,
}: Readonly<{
    label: string;
    value: string;
    suffix: string;
    toneClass: string;
}>) {
    return (
        <div>
            <dt className="text-label-micro text-text-3">{label}</dt>
            <dd
                className={cn(
                    'mt-1 font-sans text-sm font-bold tabular-nums',
                    toneClass,
                )}
            >
                {value} {suffix}
            </dd>
        </div>
    );
}
