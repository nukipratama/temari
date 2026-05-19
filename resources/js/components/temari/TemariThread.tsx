import { motion } from 'framer-motion';
import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';
import { useAnalysisTrigger } from '@/hooks/useAnalysisTrigger';
import AnalysisStatus from './AnalysisStatus';
import TemariMascot from './TemariMascot';
import type { AnalysisPayload, Mood } from '@/types/inertia';

export interface ThreadEntry {
    /** Stable key for React. */
    id: string;
    /** Iconify icon shown next to the heading. */
    icon: string;
    /** Short heading shown above the bubble (e.g. "Catatan teknis"). */
    label: string;
    /** Analysis row to render via {@see AnalysisStatus}. */
    analysis: AnalysisPayload;
    /** Tone used for the bubble accent + icon color. Defaults to brand. */
    tone?: 'brand' | 'accent' | 'pop' | 'mood';
}

interface TemariThreadProps {
    mood: Mood;
    /** Sub-label rendered next to the mood badge (e.g. "wobble"). */
    moodLabel?: string;
    /** Entries rendered as a vertical conversation thread. */
    entries: ReadonlyArray<ThreadEntry>;
    inertiaReloadProps?: string[];
    className?: string;
}

const TONE_CLASSES: Record<NonNullable<ThreadEntry['tone']>, { rail: string; icon: string }> = {
    brand: { rail: 'bg-brand-500', icon: 'text-brand-700' },
    accent: { rail: 'bg-accent-500', icon: 'text-accent-700' },
    pop: { rail: 'bg-pop-500', icon: 'text-pop-700' },
    mood: { rail: 'bg-mood-bouncy', icon: 'text-mood-bouncy' },
};

/**
 * Vertical "Temari thread" — renders one or more analyses as chat-style
 * bubbles connected by a soft vertical rail, with the mascot anchoring the
 * top. Adapts to a single-entry case as well.
 */
export default function TemariThread({
    mood,
    moodLabel,
    entries,
    inertiaReloadProps = [],
    className,
}: Readonly<TemariThreadProps>) {
    const grouped = entries.length > 1;

    return (
        <motion.section
            variants={fadeInUp}
            initial="hidden"
            animate="visible"
            className={cn(
                'relative overflow-hidden rounded-3xl border border-line bg-surface-elev p-5 shadow-sm',
                'before:pointer-events-none before:absolute before:inset-x-0 before:-top-12 before:h-24 before:bg-gradient-to-b before:from-brand-500/10 before:to-transparent',
                className,
            )}
            aria-label="Catatan Temari"
        >
            <header className="relative z-10 flex items-start gap-4">
                <TemariMascot mood={mood} sizeClass="h-28 w-28 shrink-0" idle="mood" gazeTracking ornaments />
                <div className="min-w-0 flex-1 pt-2">
                    <div className="flex flex-wrap items-baseline gap-2">
                        <span className="text-xs font-semibold uppercase tracking-wider text-ink-meta">
                            Catatan Temari
                        </span>
                        {moodLabel && (
                            <span className="rounded-full bg-surface-sunken px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-ink-meta">
                                {moodLabel}
                            </span>
                        )}
                    </div>
                    <p className="mt-1 text-xs leading-relaxed text-ink-soft">
                        Cerita, terjemahan, dan komentar Temari soal lari ini.
                    </p>
                </div>
            </header>

            <ol className="relative z-10 mt-5 space-y-4">
                {entries.map((entry, idx) => {
                    const tone = TONE_CLASSES[entry.tone ?? 'brand'];
                    const isLast = idx === entries.length - 1;
                    return (
                        <li key={entry.id} className="relative pl-7">
                            <span
                                aria-hidden
                                className={cn(
                                    'absolute left-2 top-1.5 inline-flex h-4 w-4 items-center justify-center rounded-full',
                                    tone.rail,
                                )}
                            >
                                <span className="h-1.5 w-1.5 rounded-full bg-surface-elev" />
                            </span>
                            {!isLast && (
                                <span
                                    aria-hidden
                                    className="absolute left-[15px] top-6 bottom-[-12px] w-px bg-line"
                                />
                            )}

                            <div className="flex items-center gap-1.5">
                                <Icon icon={entry.icon} width={14} height={14} className={tone.icon} aria-hidden />
                                <span className="text-xs font-semibold uppercase tracking-wider text-ink-meta">
                                    {entry.label}
                                </span>
                            </div>
                            <div className="mt-1.5 rounded-2xl bg-surface-sunken px-4 py-3">
                                <AnalysisStatus
                                    analysis={entry.analysis}
                                    inertiaReloadProps={inertiaReloadProps}
                                    size="sm"
                                    allowReanalyze={!grouped}
                                />
                            </div>
                        </li>
                    );
                })}
            </ol>

            {grouped && (
                <GroupedReanalyzeButton
                    analysis={entries[0].analysis}
                    inertiaReloadProps={inertiaReloadProps}
                />
            )}
        </motion.section>
    );
}

function GroupedReanalyzeButton({
    analysis,
    inertiaReloadProps,
}: Readonly<{ analysis: AnalysisPayload; inertiaReloadProps: string[] }>) {
    const { trigger, pending } = useAnalysisTrigger(analysis, inertiaReloadProps);
    return (
        <div className="relative z-10 mt-5 flex justify-end">
            <button
                type="button"
                onClick={trigger}
                disabled={pending}
                className="inline-flex items-center gap-1 text-xs text-ink-meta hover:text-brand-700 transition-colors disabled:opacity-50"
            >
                <Icon icon="mdi:refresh" aria-hidden />
                <span>Analisis ulang</span>
            </button>
        </div>
    );
}
