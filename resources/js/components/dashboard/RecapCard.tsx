import { useState } from 'react';
import { Icon } from '@iconify/react';
import SectionLabel from '@/components/ui/SectionLabel';
import PillButton from '@/components/ui/PillButton';
import KartuMini from '@/components/card/KartuMini';
import RecapShareModal from '@/components/dashboard/RecapShareModal';
import { cn } from '@/lib/cn';
import { formatKm } from '@/lib/pace';
import { RARITY_LABELS } from '@/lib/runcard';
import { streakLabel, weekRangeLabel, weeklyDeltaDirection, weeklyDeltaLabel } from '@/lib/weeklyRecap';
import type { RecapShareData } from '@/lib/recapShare';
import type { WeeklyRecap } from '@/types/inertia';

const DELTA_CLASS: Record<'up' | 'down' | 'flat', string> = {
    up: 'text-leaf',
    down: 'text-ember-deep',
    flat: 'text-ink-on-sky',
};

const DELTA_ICON: Record<'up' | 'down' | 'flat', string> = {
    up: 'mdi:trending-up',
    down: 'mdi:trending-down',
    flat: 'mdi:trending-neutral',
};

function toShareData(recap: WeeklyRecap): RecapShareData {
    return {
        weekStart: recap.week_start,
        weekEnd: recap.week_end,
        kmLabel: recap.this_week_km.toFixed(1),
        runs: recap.this_week_runs,
        deltaPct: recap.delta_pct,
        streakWeeks: recap.streak_weeks,
        bestCardMove: recap.best_card?.special_move ?? null,
        bestCardRarity: recap.best_card?.rarity ?? null,
        nearestGoalTitle: recap.nearest_goal?.title ?? null,
        nearestGoalRemainder: recap.nearest_goal?.remainder_label ?? null,
    };
}

export default function RecapCard({ recap }: Readonly<{ recap: WeeklyRecap }>) {
    const [shareOpen, setShareOpen] = useState(false);
    const hasRuns = recap.this_week_runs > 0;
    const direction = weeklyDeltaDirection(recap.delta_pct);
    const streak = streakLabel(recap.streak_weeks);
    const range = weekRangeLabel(recap.week_start, recap.week_end);
    // In-app equivalent of the Telegram streak nudge, for users without Telegram:
    // a live streak with no run logged yet this week is about to break.
    const streakAtRisk = recap.streak_weeks >= 1 && !hasRuns;

    return (
        <section className="relative overflow-hidden rounded-3xl bg-sky-deep p-6 text-cream">
            {/* Warm dawn glow, top-right, echoing the share image. */}
            <div
                aria-hidden
                className="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-horizon/20 blur-3xl"
            />

            <div className="relative flex items-start justify-between gap-3">
                <SectionLabel dot dotClass="bg-horizon" onSky className="mb-0">
                    Minggu Kamu
                </SectionLabel>
                {range !== '' && (
                    <span className="font-mono text-[11px] uppercase tracking-[0.12em] text-ink-on-sky">{range}</span>
                )}
            </div>

            {hasRuns ? (
                <div className="relative mt-5 grid grid-cols-1 gap-6 lg:grid-cols-[1.3fr_auto]">
                    {/* LEFT: km hero + delta + chips */}
                    <div className="min-w-0">
                        <div className="flex items-baseline gap-2">
                            <span className="font-sans text-[44px] font-bold leading-none tabular-nums tracking-[-0.02em] text-cream">
                                {formatKm(recap.this_week_km * 1000, 1)}
                            </span>
                            <span className="font-mono text-sm font-semibold uppercase tracking-[0.1em] text-ink-on-sky">
                                km
                            </span>
                        </div>

                        <div className={cn('mt-2 flex items-center gap-1.5 font-display text-base italic', DELTA_CLASS[direction])}>
                            <Icon icon={DELTA_ICON[direction]} width={18} height={18} aria-hidden />
                            <span>{weeklyDeltaLabel(recap.delta_pct)}</span>
                        </div>

                        <div className="mt-5 flex flex-wrap gap-2">
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-cream/[0.08] px-3 py-1.5 text-xs font-medium text-cream">
                                <Icon icon="mdi:run" width={14} height={14} aria-hidden className="text-horizon" />
                                {recap.this_week_runs} lari minggu ini
                            </span>
                            {streak && (
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-cream/[0.08] px-3 py-1.5 text-xs font-medium text-cream">
                                    <Icon icon="mdi:fire" width={14} height={14} aria-hidden className="text-horizon" />
                                    {streak}
                                </span>
                            )}
                        </div>

                        {recap.nearest_goal && (
                            <p className="mt-4 font-display text-sm italic leading-relaxed text-ink-on-sky">
                                <span className="text-horizon">{recap.nearest_goal.remainder_label}</span> ke{' '}
                                {recap.nearest_goal.title}.
                            </p>
                        )}

                        <div className="mt-5">
                            <PillButton tone="outline" size="sm" onClick={() => setShareOpen(true)}>
                                <Icon icon="mdi:share-variant" width={14} height={14} aria-hidden className="mr-1.5" />
                                Bagikan minggu ini
                            </PillButton>
                        </div>
                    </div>

                    {/* RIGHT: best card of the week */}
                    {recap.best_card && (
                        <div className="flex flex-col items-center gap-2">
                            <span className="font-mono text-[10px] font-bold uppercase tracking-[0.14em] text-ink-on-sky">
                                Kartu terbaik
                            </span>
                            <KartuMini
                                name={recap.best_card.special_move}
                                rarity={recap.best_card.rarity}
                                mood={recap.best_card.mood ?? undefined}
                                polyline={recap.best_card.polyline}
                            />
                            <span className="text-center font-display text-xs italic text-ink-on-sky">
                                {RARITY_LABELS[recap.best_card.rarity]}
                                {recap.best_card.distance_km != null && (
                                    <> · {recap.best_card.distance_km.toFixed(1)} km</>
                                )}
                            </span>
                        </div>
                    )}
                </div>
            ) : (
                <div className="relative mt-5">
                    <p className="font-display text-lg italic leading-relaxed text-cream">
                        {streakAtRisk
                            ? `Streak ${recap.streak_weeks} minggu-mu belum keisi minggu ini. Lari dikit dulu biar kejaga ya.`
                            : 'Minggu ini masih kosong. Yuk catat lari pertama biar Temari punya bahan cerita.'}
                    </p>
                    <p className="mt-2 font-sans text-sm leading-relaxed text-ink-on-sky">
                        Satu lari aja udah cukup buat ngisi rekap minggu kamu.
                    </p>
                    {recap.nearest_goal && (
                        <p className="mt-4 font-display text-sm italic text-ink-on-sky">
                            <span className="text-horizon">{recap.nearest_goal.remainder_label}</span> ke{' '}
                            {recap.nearest_goal.title}.
                        </p>
                    )}
                </div>
            )}

            {shareOpen && <RecapShareModal recap={toShareData(recap)} onClose={() => setShareOpen(false)} />}
        </section>
    );
}
