import { motion } from 'framer-motion';
import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';
import TemariMascot from './TemariMascot';
import TemariPeek from './TemariPeek';
import DegradedChip from './DegradedChip';
import type { BriefingResult, RecoveryTone } from '@/types/inertia';

const PEEK_LINES = [
    'Lagi nungguin lari berikutnya nih',
    'Coba liat pace minggu lalu, makin smooth lho',
    'Inget istirahat ya, jangan ngebut terus',
    'Form-mu lagi oke nih, manfaatin~',
    'Tap aku buat reaksi 🌀',
] as const;

interface BriefingCardProps {
    briefing: BriefingResult;
}

/**
 * Dashboard hero tier 1 — bigger mascot, bigger headline, richer gradient,
 * body text at `text-base ink` not `text-sm ink-soft`. FM fadeInUp on
 * mount. This card should clearly read as "the most important thing on
 * the page".
 */
export default function BriefingCard({ briefing }: Readonly<BriefingCardProps>) {
    const vibeBg = vibeBackground(briefing.vibeState);
    const recoveryClass = recoveryChipClass(briefing.recoveryTone);

    return (
        <motion.div
            variants={fadeInUp}
            initial="hidden"
            animate="visible"
            className={cn('rounded-3xl border border-line p-6 shadow-sm transition-colors duration-300 dark:border-line-dark sm:p-8', vibeBg)}
        >
            <div className="flex flex-col items-start gap-6 sm:flex-row sm:items-center sm:gap-8">
                <div className="relative shrink-0">
                    <TemariMascot
                        mood={briefing.mood}
                        sigilPattern={briefing.sigilPattern}
                        accessory={briefing.accessory}
                        sizeClass="h-32 w-32 sm:h-36 sm:w-36"
                        sigilPixels={144}
                        idle="mood"
                        gazeTracking
                        interactive
                        hoverable
                        aria-label="Temari — tap untuk reaksi"
                    />
                    <TemariPeek lines={PEEK_LINES} />
                </div>

                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-baseline gap-2">
                        <span className="text-xs font-semibold uppercase tracking-wider text-ink-meta dark:text-ink-meta-dark">
                            Briefing Temari
                        </span>
                        <span className="text-xs font-semibold text-ink dark:text-ink-dark">
                            {briefing.vibeEmoji} {briefing.vibeLabel}
                        </span>
                        {briefing.degraded && <DegradedChip />}
                    </div>
                    <p className="mt-3 text-2xl font-semibold leading-snug tracking-tight text-ink dark:text-ink-dark">
                        {briefing.headlineLine}
                    </p>
                    <p className="mt-2 text-base leading-relaxed text-ink dark:text-ink-dark">
                        {briefing.suggestionLine}
                    </p>

                    <div className="mt-4 flex flex-wrap gap-2">
                        <span className={cn('inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold', recoveryClass)}>
                            <Icon icon="mdi:heart-pulse" width={14} height={14} aria-hidden />
                            {briefing.recoveryLabel}
                        </span>
                        {briefing.streakLabel !== null && (
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-surface-elev/70 px-3 py-1 text-xs font-semibold text-ink dark:bg-surface-dark-elev/70 dark:text-ink-dark">
                                <Icon icon="mdi:run" width={14} height={14} aria-hidden />
                                {briefing.streakLabel}
                            </span>
                        )}
                    </div>
                </div>
            </div>
        </motion.div>
    );
}

function vibeBackground(state: string): string {
    switch (state) {
        case 'pumped':
        case 'fresh':
        case 'bouncy':
            return 'bg-gradient-to-br from-brand-50 via-accent-50/60 to-brand-100 dark:from-brand-900/40 dark:via-accent-900/20 dark:to-brand-800/40';
        case 'cooked':
        case 'stretched_thin':
            return 'bg-gradient-to-br from-mood-cooked/10 via-pop-50/40 to-mood-cooked/20 dark:from-mood-cooked/30 dark:to-mood-cooked/20';
        case 'worn_down':
            return 'bg-gradient-to-br from-accent-50 via-mood-glow/10 to-accent-100 dark:from-accent-900/40 dark:to-accent-800/40';
        case 'hibernating':
            return 'bg-gradient-to-br from-surface via-mood-hibernate/10 to-mood-hibernate/20 dark:from-surface-dark-elev dark:to-mood-hibernate/30';
        default:
            return 'bg-gradient-to-br from-mood-spinning/10 via-brand-50/40 to-mood-spinning/20 dark:from-mood-spinning/30 dark:to-mood-spinning/20';
    }
}

function recoveryChipClass(tone: RecoveryTone): string {
    switch (tone) {
        case 'positive':
            return 'bg-mood-bouncy/15 text-mood-bouncy';
        case 'warning':
            return 'bg-mood-glow/15 text-mood-glow';
        case 'alert':
            return 'bg-mood-cooked/15 text-mood-cooked';
        default:
            return 'bg-surface-elev/70 text-ink dark:bg-surface-dark-elev/70 dark:text-ink-dark';
    }
}
