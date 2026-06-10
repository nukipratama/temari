import { Icon } from '@iconify/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useState } from 'react';
import ConfettiBurst from '@/components/ConfettiBurst';
import DecorativeBlur from '@/components/DecorativeBlur';
import { cn } from '@/lib/cn';
import { postJson } from '@/lib/http';

export interface MilestoneEntry {
    kind: 'pr' | 'longest_ever' | 'first_ever_distance' | 'first_ever_pace';
    label: string;
    body: string;
    priority?: number;
}

export interface PendingMilestone {
    activity_id: number;
    milestones: ReadonlyArray<MilestoneEntry>;
}

interface MilestoneBannerProps {
    pending: PendingMilestone | null;
}

const CONFETTI_KINDS: ReadonlyArray<MilestoneEntry['kind']> = ['pr', 'longest_ever'];

/**
 * Loud Dashboard banner that celebrates the freshest first-ever / PR /
 * longest-ever milestone on the user's most-recent activity. Confetti
 * fires once for PR and longest-ever kinds (the headline moments);
 * smaller first-evers get just a shimmer to avoid celebration fatigue.
 *
 * Dismiss is user-initiated only (no auto-close timer) — accidental
 * scroll-past shouldn't burn the moment. The dismiss POST nulls the
 * cached payload on the activity so it never reappears.
 */
export default function MilestoneBanner({ pending }: Readonly<MilestoneBannerProps>) {
    const [open, setOpen] = useState(true);
    const [showAll, setShowAll] = useState(false);

    if (pending === null || pending.milestones.length === 0) return null;

    const primary = pending.milestones[0];
    const extras = pending.milestones.slice(1);
    const confettiKey = CONFETTI_KINDS.includes(primary.kind) ? `milestone-${pending.activity_id}` : null;

    const dismiss = () => {
        setOpen(false);
        // The dismiss endpoint returns plain JSON, so it must go through fetch —
        // Inertia's router rejects non-Inertia responses.
        void postJson(`/api/milestones/${pending.activity_id}/dismiss`);
    };

    return (
        <AnimatePresence>
            {open && (
                <motion.section
                    initial={{ opacity: 0, y: -8 }}
                    animate={{ opacity: 1, y: 0 }}
                    exit={{ opacity: 0, y: -8 }}
                    transition={{ duration: 0.25 }}
                    role="status"
                    aria-label="Milestone Temari"
                    className="relative mb-6 overflow-hidden rounded-2xl border border-citrus/40 bg-gradient-to-br from-citrus/10 via-surface-warm to-horizon/10 p-4 shadow-md sm:rounded-3xl sm:p-6"
                >
                    <ConfettiBurst burstKey={confettiKey} />
                    <DecorativeBlur className="-right-16 -top-16 h-48 w-48 bg-citrus/25" />
                    <DecorativeBlur className="-bottom-16 -left-10 h-40 w-40 bg-horizon/25" />

                    <div className="relative flex items-start justify-between gap-3">
                        <div className="flex items-start gap-3">
                            <span
                                aria-hidden
                                className={cn(
                                    'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-white shadow-md ring-2 ring-white sm:h-12 sm:w-12 sm:rounded-2xl',
                                    iconBgFor(primary.kind),
                                )}
                            >
                                <Icon icon={iconFor(primary.kind)} width={22} height={22} />
                            </span>
                            <div className="min-w-0">
                                <p className="font-mono text-xs font-semibold uppercase tracking-wider text-citrus-deep">
                                    Milestone hari ini
                                </p>
                                <h2 className="mt-0.5 text-lg font-bold leading-tight tracking-tight text-ink sm:text-xl">
                                    {primary.label}
                                </h2>
                                <p className="mt-1 text-sm leading-relaxed text-ink-2">{primary.body}</p>

                                {extras.length > 0 && !showAll && (
                                    <button
                                        type="button"
                                        onClick={() => setShowAll(true)}
                                        className="focus-ring mt-2 rounded text-xs font-semibold text-citrus-deep underline-offset-2 hover:underline"
                                    >
                                        + {extras.length} milestone lainnya
                                    </button>
                                )}
                                {showAll && extras.length > 0 && (
                                    <ul className="mt-3 space-y-1.5 text-sm text-ink-2">
                                        {extras.map((m) => (
                                            <li key={`${m.kind}-${m.label}`}>
                                                <span className="font-semibold text-ink">{m.label}</span>, {m.body}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </div>

                        <button
                            type="button"
                            onClick={dismiss}
                            aria-label="Tutup"
                            className="focus-ring shrink-0 rounded-full p-1.5 text-ink-3 transition hover:bg-line/40 hover:text-ink"
                        >
                            <Icon icon="mdi:close" width={18} height={18} aria-hidden />
                        </button>
                    </div>
                </motion.section>
            )}
        </AnimatePresence>
    );
}

function iconFor(kind: MilestoneEntry['kind']): string {
    switch (kind) {
        case 'pr':
            return 'mdi:trophy';
        case 'longest_ever':
            return 'mdi:map-marker-distance';
        case 'first_ever_distance':
            return 'mdi:flag-checkered';
        case 'first_ever_pace':
            return 'mdi:flash';
    }
}

function iconBgFor(kind: MilestoneEntry['kind']): string {
    switch (kind) {
        case 'pr':
            return 'bg-citrus';
        case 'longest_ever':
            return 'bg-horizon';
        case 'first_ever_distance':
            return 'bg-leaf';
        case 'first_ever_pace':
            return 'bg-mood-mumet';
    }
}
