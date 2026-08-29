import { AnimatePresence, motion } from 'framer-motion';
import { memo, useState } from 'react';

import type { ActivityDetail, Mood, Rarity, RunCard } from '@/types/inertia';

import KartuMini from '@/components/card/KartuMini';
import MotionLink from '@/components/MotionLink';
import Temari from '@/components/temari/Temari';
import { Icon } from '@/components/ui/Icon';
import MoodChip from '@/components/ui/MoodChip';
import { useReducedMotion } from '@/hooks/useReducedMotion';
import { cn } from '@/lib/cn';
import { MOOD_LABEL } from '@/lib/mood';
import { moodFromActivity } from '@/lib/moodFromActivity';
import {
    formatDurationHMS,
    formatKm,
    formatNaiveIdDate,
    formatNaiveTimeId,
    formatPace,
    paceSecPerKm,
} from '@/lib/pace';
import { renderBold } from '@/lib/richText';
import { activityUrl } from '@/lib/routes';
import { badgeEmblem, badgeName, RARITY_HEX } from '@/lib/runcard';
import { MOOD_TO_POSE } from '@/lib/temariPose';

export interface RunNote {
    oneline: string;
    mood: Mood;
}

interface RunListRowProps {
    detail: ActivityDetail;
    mood?: Mood | null;
    note?: RunNote | null;
    /** The run's earned Kartu, when one has been generated (see RunCardReveal). */
    runCard?: RunCard | null;
}

function RunListRow({
    detail,
    mood = null,
    note = null,
    runCard = null,
}: Readonly<RunListRowProps>) {
    const km = formatKm(detail.distance);
    const paceSec = paceSecPerKm(detail.elapsed_time, detail.distance);
    const paceLabel = paceSec != null ? formatPace(paceSec) : '—';
    const hr =
        detail.average_heartrate != null
            ? Math.round(detail.average_heartrate)
            : null;
    const trimp =
        detail.trimp_edwards != null ? Math.round(detail.trimp_edwards) : null;
    const safeMood: Mood = note?.mood ?? mood ?? moodFromActivity(detail);
    const startTime = formatNaiveTimeId(detail.start_date_local);

    if (runCard) {
        return (
            <MotionLink
                href={activityUrl(detail)}
                className="flex items-start gap-4 border-b border-border px-5 py-4 text-sm transition last:border-b-0 hover:bg-background sm:gap-6"
            >
                <RunCardReveal
                    name={runCard.special_move}
                    rarity={runCard.rarity}
                    mood={safeMood}
                    polyline={detail.summary_polyline}
                />
                <div className="flex min-w-0 flex-1 flex-col gap-2">
                    <div className="min-w-0">
                        <div className="line-clamp-2 font-medium text-foreground">
                            {detail.name ?? 'Run'}
                        </div>
                        <div className="mt-0.5 text-xs text-text-3">
                            {formatNaiveIdDate(detail.start_date_local)}
                            {startTime && (
                                <span className="text-text-2">
                                    {' '}
                                    · {startTime}
                                </span>
                            )}
                        </div>
                        <div className="mt-1.5 flex flex-wrap items-baseline gap-x-2.5 gap-y-1 text-xs tabular-nums text-text-3">
                            <span className="font-semibold text-text-2">
                                {km} km
                            </span>
                            <span>
                                {formatDurationHMS(detail.elapsed_time)}
                            </span>
                            <span>{paceLabel} /km</span>
                            <span>{hr ?? '-'} bpm</span>
                            <span>{trimp ?? '-'} TRIMP</span>
                        </div>
                        <div className="mt-2 flex flex-wrap items-center gap-1.5">
                            <MoodChip mood={safeMood} size="sm" />
                            {(runCard.badges ?? []).map((slug) => (
                                <span
                                    key={slug}
                                    aria-hidden
                                    title={badgeName(slug)}
                                    className="text-sm leading-none"
                                >
                                    {badgeEmblem(slug)}
                                </span>
                            ))}
                        </div>
                    </div>
                    {note && (
                        <div className="flex items-start gap-2 rounded-xl bg-accent/60 px-3 py-2 text-xs leading-relaxed text-foreground">
                            <Icon
                                icon="mdi:comment-quote-outline"
                                width={14}
                                height={14}
                                aria-hidden
                                className="mt-0.5 shrink-0 text-leaf-ink"
                            />
                            <p className="min-w-0">
                                {renderBold(note.oneline)}
                            </p>
                        </div>
                    )}
                </div>
            </MotionLink>
        );
    }

    return (
        <MotionLink
            href={activityUrl(detail)}
            className="flex items-start gap-4 border-b border-border px-5 py-4 text-sm transition last:border-b-0 hover:bg-background"
        >
            <Temari
                pose={MOOD_TO_POSE[safeMood]}
                size={64}
                className="shrink-0"
                aria-label={`mood ${MOOD_LABEL[safeMood]}`}
            />
            <div className="flex min-w-0 flex-1 flex-col gap-2">
                <div className="flex items-center gap-4">
                    <div className="min-w-0 flex-1">
                        {/* Wrap to two lines instead of a hard truncate so a run's
                            distinguishing trailing number/date survives at narrow widths. */}
                        <div className="line-clamp-2 font-medium text-foreground">
                            {detail.name ?? 'Run'}
                        </div>
                        <div className="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-text-3">
                            <span>
                                {formatNaiveIdDate(detail.start_date_local)}
                                {startTime && (
                                    <span className="text-text-2">
                                        {' '}
                                        · {startTime}
                                    </span>
                                )}
                            </span>
                            <MoodChip mood={safeMood} size="sm" />
                        </div>
                    </div>
                    <div className="flex items-center gap-5 tabular-nums">
                        <Cell value={km} unit="km" emphasize />
                        <Cell
                            value={formatDurationHMS(detail.elapsed_time)}
                            unit="duration"
                            hideOnNarrow="sm"
                        />
                        <Cell value={paceLabel} unit="/km" hideOnNarrow="sm" />
                        <Cell value={hr ?? '-'} unit="bpm" hideOnNarrow="md" />
                        <Cell
                            value={trimp ?? '-'}
                            unit="TRIMP"
                            hideOnNarrow="md"
                        />
                    </div>
                </div>
                {note && (
                    <div className="flex items-start gap-2 rounded-xl bg-accent/60 px-3 py-2 text-xs leading-relaxed text-foreground">
                        <Icon
                            icon="mdi:comment-quote-outline"
                            width={14}
                            height={14}
                            aria-hidden
                            className="mt-0.5 shrink-0 text-leaf-ink"
                        />
                        <p className="min-w-0">{renderBold(note.oneline)}</p>
                    </div>
                )}
            </div>
        </MotionLink>
    );
}

interface RunCardRevealProps {
    name: string;
    rarity: Rarity;
    mood: Mood;
    polyline?: string | null;
}

/**
 * The earned Kartu ({@link KartuMini}), sized up to lead the row. The first
 * time it scrolls into view it fires the same ignition-ring flash as a fresh
 * pull in resources/js/components/card/CardReveal.tsx, in the card's own
 * rarity colour.
 */
function RunCardReveal({
    name,
    rarity,
    mood,
    polyline,
}: Readonly<RunCardRevealProps>) {
    const rarityHex = RARITY_HEX[rarity];
    const [ignited, setIgnited] = useState(false);
    const reducedMotion = useReducedMotion();

    return (
        <motion.div
            className="relative w-28 flex-none sm:w-36"
            onViewportEnter={() => setIgnited(true)}
            viewport={{ once: true, amount: 0.6 }}
        >
            {!reducedMotion && (
                <AnimatePresence>
                    {ignited && (
                        <motion.span
                            key="ignite"
                            aria-hidden
                            initial={{ opacity: 0.85, scale: 0.96 }}
                            animate={{ opacity: 0, scale: 1.18 }}
                            transition={{ duration: 0.7, ease: 'easeOut' }}
                            className="pointer-events-none absolute inset-0 rounded-xl"
                            style={{
                                boxShadow: `0 0 0 3px ${rarityHex}, 0 0 36px 8px ${rarityHex}`,
                            }}
                        />
                    )}
                </AnimatePresence>
            )}
            <KartuMini
                name={name}
                rarity={rarity}
                mood={mood}
                polyline={polyline}
                className="w-full"
            />
        </motion.div>
    );
}

interface CellProps {
    value: string | number;
    unit: string;
    emphasize?: boolean;
    hideOnNarrow?: 'sm' | 'md';
}

const HIDE_CLASSES = {
    sm: 'hidden sm:block',
    md: 'hidden md:block',
} as const;

function Cell({
    value,
    unit,
    emphasize = false,
    hideOnNarrow,
}: Readonly<CellProps>) {
    return (
        <div
            className={cn(
                'text-center',
                hideOnNarrow && HIDE_CLASSES[hideOnNarrow],
            )}
        >
            <div className={cn('text-foreground', emphasize && 'font-bold')}>
                {value}
            </div>
            <div className="text-label-micro text-text-2">{unit}</div>
        </div>
    );
}

export default memo(RunListRow);
