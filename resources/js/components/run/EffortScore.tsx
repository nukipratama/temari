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
import { activityUrl } from '@/lib/routes';

const UNRATED_START = 5;

const SEGMENT_FILL = {
    easy: 'bg-leaf',
    steady: 'bg-citrus',
    hard: 'bg-ember',
} as const;

const ZONES = [
    { label: 'easy', span: 'col-span-3' },
    { label: 'steady', span: 'col-span-3' },
    { label: 'hard', span: 'col-span-4' },
] as const;

const SCORES = Array.from(
    { length: EFFORT_MAX - EFFORT_MIN + 1 },
    (_, i) => EFFORT_MIN + i,
);

const quietButton =
    'pressable focus-ring rounded text-[0.71875rem] font-bold text-text-2 hover:text-foreground disabled:opacity-60';

function HeroRow({ score }: Readonly<{ score: number | null }>) {
    return (
        <p className="mt-2 flex flex-wrap items-baseline gap-x-1.5">
            <span className={cn('text-stat', score === null && 'text-text-3')}>
                {score ?? '–'}
            </span>
            <span className="text-label-small text-text-3">/ 10</span>
            <span
                className={cn(
                    'ml-1 text-sm font-semibold',
                    score === null
                        ? 'text-text-3'
                        : EFFORT_ICON_CLASS[effortBand(score)],
                )}
            >
                {score === null ? 'drag to rate' : effortWord(score)}
            </span>
        </p>
    );
}

/**
 * How hard a run without heart rate felt, on Foster's CR-10 scale: the score
 * gives that run its load. The 3 / 3 / 4 colour grouping is display only.
 */
export default function EffortScore({
    prompt,
    runName = null,
}: Readonly<{ prompt: PerceivedEffortPrompt; runName?: string | null }>) {
    const inputId = useId();
    const [draft, setDraft] = useState<number | null>(null);
    const [editing, setEditing] = useState(prompt.score === null);
    const [processing, setProcessing] = useState(false);
    const error = usePage<SharedProps>().props.errors?.score;
    const url = `${activityUrl(prompt)}/effort`;
    const visits = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
        onSuccess: () => {
            setDraft(null);
            setEditing(false);
        },
    };
    const showsSaved = !editing && prompt.score !== null;

    return (
        <div>
            <Eyebrow token="small" tone="ink-3" as="div">
                <label htmlFor={inputId}>how hard did it feel</label>
            </Eyebrow>
            {runName !== null && (
                <p className="mt-1 text-xs text-text-2">{runName}</p>
            )}

            <HeroRow score={showsSaved ? prompt.score : draft} />

            {showsSaved ? (
                <div className="mt-2 flex gap-4">
                    <button
                        type="button"
                        className={quietButton}
                        disabled={processing}
                        onClick={() => {
                            setDraft(prompt.score);
                            setEditing(true);
                        }}
                    >
                        change
                    </button>
                    <button
                        type="button"
                        className={quietButton}
                        disabled={processing}
                        onClick={() => router.delete(url, visits)}
                    >
                        clear
                    </button>
                </div>
            ) : (
                <>
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
                            onChange={(event) =>
                                setDraft(Number(event.target.value))
                            }
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
                    <div className="mt-4 flex items-center justify-end gap-4 border-t border-dashed border-border pt-4">
                        {prompt.score !== null && (
                            <button
                                type="button"
                                className={quietButton}
                                disabled={processing}
                                onClick={() => {
                                    setDraft(null);
                                    setEditing(false);
                                }}
                            >
                                cancel
                            </button>
                        )}
                        <PillButton
                            tone="horizon"
                            size="sm"
                            disabled={draft === null || processing}
                            onClick={() =>
                                router.patch(url, { score: draft }, visits)
                            }
                        >
                            save
                        </PillButton>
                    </div>
                </>
            )}
            {error && (
                <p className="mt-2 text-xs text-ember-ink" role="alert">
                    {error}
                </p>
            )}
        </div>
    );
}
