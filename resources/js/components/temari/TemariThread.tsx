import { AnimatePresence, motion } from 'framer-motion';
import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';
import { useAnalysisTrigger } from '@/hooks/useAnalysisTrigger';
import AnalysisStatus from './AnalysisStatus';
import Temari from './Temari';
import { MOOD_TO_POSE } from '@/lib/temariPose';
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
    /** Sub-label rendered next to the mood badge (e.g. "lemes"). */
    moodLabel?: string;
    /** Entries rendered as a vertical conversation thread. */
    entries: ReadonlyArray<ThreadEntry>;
    inertiaReloadProps?: string[];
    className?: string;
}

const TONE_CLASSES: Record<NonNullable<ThreadEntry['tone']>, { rail: string; icon: string }> = {
    brand: { rail: 'bg-leaf', icon: 'text-leaf-deep' },
    accent: { rail: 'bg-horizon', icon: 'text-horizon-deep' },
    pop: { rail: 'bg-citrus', icon: 'text-citrus-deep' },
    mood: { rail: 'bg-mood-enteng', icon: 'text-mood-enteng' },
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
                'relative overflow-hidden rounded-2xl border border-line bg-surface-elev p-4 shadow-sm sm:rounded-3xl sm:p-5',
                'before:pointer-events-none before:absolute before:inset-x-0 before:-top-12 before:h-24 before:bg-gradient-to-b before:from-leaf/10 before:to-transparent',
                className,
            )}
            aria-label="Catatan Temari"
        >
            <header className="relative z-10 flex items-start gap-3 sm:gap-4">
                <Temari pose={MOOD_TO_POSE[mood]} size={112} animate className="shrink-0" />
                <div className="min-w-0 flex-1 pt-2">
                    <div className="flex flex-wrap items-baseline gap-2">
                        <span className="font-mono text-xs font-bold uppercase tracking-wider text-ink-2">
                            Catatan Temari
                        </span>
                        {moodLabel && (
                            <span className="rounded-full bg-surface-sunken px-2 py-0.5 font-mono text-[11px] font-bold uppercase tracking-wider text-ink-2">
                                {moodLabel}
                            </span>
                        )}
                    </div>
                    <p className="mt-1 text-xs leading-relaxed text-ink-2">
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
                                <span className="font-mono text-xs font-bold uppercase tracking-wider text-ink-2">
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
                    entries={entries}
                    inertiaReloadProps={inertiaReloadProps}
                />
            )}
        </motion.section>
    );
}

function GroupedReanalyzeButton({
    entries,
    inertiaReloadProps,
}: Readonly<{ entries: ReadonlyArray<ThreadEntry>; inertiaReloadProps: string[] }>) {
    const { trigger, pending } = useAnalysisTrigger(entries[0].analysis, inertiaReloadProps);
    // Hide is driven by *prop* status, not the local `pending` flag, so the
    // button disappears at the same Inertia-reload frame the per-row spinners
    // appear. Tying both transitions to one source kills the "button gone but
    // content still done" intermediate flicker. The local `pending` keeps the
    // button disabled in the meantime for click feedback.
    const anyRowInFlight = entries.some((entry) => {
        const status = entry.analysis.status;
        return status === 'queued' || status === 'processing';
    });

    return (
        <AnimatePresence initial={false}>
            {!anyRowInFlight && (
                <motion.div
                    key="grouped-reanalyze"
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    exit={{ opacity: 0 }}
                    transition={{ duration: 0.18 }}
                    className="relative z-10 mt-5 flex justify-end"
                >
                    <button
                        type="button"
                        onClick={trigger}
                        disabled={pending}
                        className="focus-ring rounded inline-flex items-center gap-1 text-xs text-ink-3 hover:text-leaf-deep transition-colors disabled:opacity-50 disabled:cursor-wait"
                    >
                        <Icon icon="mdi:auto-awesome" aria-hidden />
                        <span>Baca ulang</span>
                    </button>
                </motion.div>
            )}
        </AnimatePresence>
    );
}
