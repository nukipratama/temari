import { router, usePage } from '@inertiajs/react';
import { useId, useState } from 'react';

import type { PerceivedEffortPrompt, SharedProps } from '@/types/inertia';

import Eyebrow from '@/components/ui/Eyebrow';
import PillButton from '@/components/ui/PillButton';
import { cn } from '@/lib/cn';
import { EFFORT_ICON_CLASS } from '@/lib/effort';
import {
    EFFORT_MAX,
    EFFORT_MIN,
    effortBand,
    effortWord,
} from '@/lib/perceivedEffort';

const UNRATED_START = 5;

const SEGMENT_FILL = {
    easy: 'bg-leaf',
    steady: 'bg-citrus',
    hard: 'bg-ember',
} as const;

const CHIP_TINT = {
    easy: 'bg-leaf/15',
    steady: 'bg-citrus/15',
    hard: 'bg-ember/15',
} as const;

const ZONES = [
    { label: 'easy', span: 'col-span-4' },
    { label: 'steady', span: 'col-span-2' },
    { label: 'hard', span: 'col-span-4' },
] as const;

const SCORES = Array.from(
    { length: EFFORT_MAX - EFFORT_MIN + 1 },
    (_, i) => EFFORT_MIN + i,
);

const quietButton =
    'pressable focus-ring rounded text-[0.71875rem] font-bold text-text-2 hover:text-foreground disabled:opacity-60';

function effortUrl(activityId: number): string {
    return `/activities/${activityId}/effort`;
}

function ScoreError() {
    const error = usePage<SharedProps>().props.errors?.score;

    return error ? (
        <p className="mt-2 text-xs text-ember-ink" role="alert">
            {error}
        </p>
    ) : null;
}

export function EffortChip({
    score,
    className,
}: Readonly<{ score: number; className?: string }>) {
    const band = effortBand(score);

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-1 text-label-micro',
                CHIP_TINT[band],
                EFFORT_ICON_CLASS[band],
                className,
            )}
        >
            <span className="font-mono font-bold tabular-nums">{score}/10</span>
            {` · ${effortWord(score)}`}
        </span>
    );
}

/** The saved score as a chip; tapping it reopens the picker. */
export function EffortSaved({
    score,
    onChange,
}: Readonly<{ score: number; onChange: () => void }>) {
    return (
        <button
            type="button"
            className="focus-ring rounded-full"
            aria-label={`change effort score, ${score} of 10, ${effortWord(score)}`}
            onClick={onChange}
        >
            <EffortChip score={score} />
        </button>
    );
}

/** How hard a run felt, on Foster's CR-10 scale. */
export function EffortPicker({
    activityId,
    saved,
    runName = null,
    onClose,
}: Readonly<{
    activityId: number;
    saved: number | null;
    runName?: string | null;
    onClose: () => void;
}>) {
    const inputId = useId();
    const [draft, setDraft] = useState<number | null>(saved);
    const [processing, setProcessing] = useState(false);

    return (
        <div>
            <Eyebrow token="small" tone="ink-3" as="div">
                <label htmlFor={inputId}>how hard did it feel</label>
            </Eyebrow>
            {runName !== null && (
                <p className="mt-1 text-xs text-text-2">{runName}</p>
            )}

            <div
                data-score-row
                className="mt-2 flex items-center justify-between gap-3"
            >
                <p className="flex flex-wrap items-baseline gap-x-1.5">
                    <span
                        className={cn(
                            'text-stat',
                            draft === null && 'text-text-3',
                        )}
                    >
                        {draft ?? '–'}
                    </span>
                    <span className="text-label-small text-text-3">/ 10</span>
                    <span
                        className={cn(
                            'ml-1 text-sm font-semibold',
                            draft === null
                                ? 'text-text-3'
                                : EFFORT_ICON_CLASS[effortBand(draft)],
                        )}
                    >
                        {draft === null ? 'drag to rate' : effortWord(draft)}
                    </span>
                </p>
                <div className="flex flex-none items-center gap-4">
                    {saved !== null && (
                        <>
                            <button
                                type="button"
                                className={quietButton}
                                disabled={processing}
                                onClick={() =>
                                    router.delete(effortUrl(activityId), {
                                        preserveScroll: true,
                                        onStart: () => setProcessing(true),
                                        onFinish: () => setProcessing(false),
                                        onSuccess: onClose,
                                    })
                                }
                            >
                                clear
                            </button>
                            <button
                                type="button"
                                className={quietButton}
                                disabled={processing}
                                onClick={onClose}
                            >
                                cancel
                            </button>
                        </>
                    )}
                    <PillButton
                        tone="horizon"
                        size="sm"
                        disabled={draft === null || processing}
                        onClick={() =>
                            router.patch(
                                effortUrl(activityId),
                                { score: draft },
                                {
                                    preserveScroll: true,
                                    onStart: () => setProcessing(true),
                                    onFinish: () => setProcessing(false),
                                    onSuccess: onClose,
                                },
                            )
                        }
                    >
                        save
                    </PillButton>
                </div>
            </div>

            <div className="relative mt-3 h-6">
                <div
                    className="absolute inset-x-0 top-1/2 flex h-2 -translate-y-1/2 gap-0.5"
                    aria-hidden
                >
                    {SCORES.map((score) => (
                        <span
                            key={score}
                            className={cn(
                                'flex-1 first:rounded-l-sm last:rounded-r-sm',
                                SEGMENT_FILL[effortBand(score)],
                            )}
                        />
                    ))}
                </div>
                <input
                    id={inputId}
                    type="range"
                    min={EFFORT_MIN}
                    max={EFFORT_MAX}
                    step={1}
                    value={draft ?? UNRATED_START}
                    data-rated={draft !== null}
                    aria-valuetext={
                        draft === null
                            ? 'not rated yet'
                            : `${draft} of 10, ${effortWord(draft)}`
                    }
                    onChange={(event) => setDraft(Number(event.target.value))}
                    className="effort-range absolute inset-y-0 left-[calc(5%-0.625rem)] w-[calc(90%+1.25rem)]"
                />
            </div>
            <div
                className="mt-1.5 grid grid-cols-10 gap-0.5 text-center"
                aria-hidden
            >
                {ZONES.map((zone) => (
                    <span
                        key={zone.label}
                        className={cn(
                            'text-label-micro text-text-3',
                            zone.span,
                        )}
                    >
                        {zone.label}
                    </span>
                ))}
            </div>
            <ScoreError />
        </div>
    );
}

/** The picker, collapsing to the saved chip once the run has a score. */
export default function EffortScore({
    prompt,
    runName = null,
}: Readonly<{ prompt: PerceivedEffortPrompt; runName?: string | null }>) {
    const [editing, setEditing] = useState(false);

    if (prompt.score === null || editing) {
        return (
            <EffortPicker
                activityId={prompt.activity_id}
                saved={prompt.score}
                runName={runName}
                onClose={() => setEditing(false)}
            />
        );
    }

    return (
        <div>
            <Eyebrow token="small" tone="ink-3" as="div">
                how hard did it feel
            </Eyebrow>
            {runName !== null && (
                <p className="mt-1 text-xs text-text-2">{runName}</p>
            )}
            <div className="mt-2">
                <EffortSaved
                    score={prompt.score}
                    onChange={() => setEditing(true)}
                />
            </div>
            <ScoreError />
        </div>
    );
}
