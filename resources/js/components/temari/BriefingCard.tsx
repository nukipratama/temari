import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import { MOOD_FACE, MASCOT_GRADIENT, moodRing, moodSigilColor } from '@/lib/mood';
import TemariSigil from './TemariSigil';
import DegradedChip from './DegradedChip';
import type { BriefingResult, RecoveryTone } from '@/types/inertia';

interface BriefingCardProps {
    briefing: BriefingResult;
}

/**
 * Hero promotion: Temari sigil ~140px + vibe pill + 2-line headline +
 * recovery & streak chips. Mood transitions handled via Tailwind transition
 * on the gradient + ring (no Framer Motion in this first pass).
 */
export default function BriefingCard({ briefing }: Readonly<BriefingCardProps>) {
    const vibeBg = vibeBackground(briefing.vibeState);
    const recoveryClass = recoveryChipClass(briefing.recoveryTone);
    const sigilColor = moodSigilColor(briefing.mood);

    return (
        <div className={cn('rounded-3xl border border-line p-6 transition-colors duration-300 dark:border-line-dark', vibeBg)}>
            <div className="flex flex-col items-start gap-6 sm:flex-row sm:items-center">
                <div className="relative shrink-0">
                    <div
                        className={cn(
                            'relative flex h-32 w-32 items-center justify-center rounded-full ring-4 transition-all',
                            MASCOT_GRADIENT,
                            moodRing(briefing.mood),
                        )}
                    >
                        <span className="relative z-10 text-5xl">{MOOD_FACE[briefing.mood]}</span>
                        <TemariSigil
                            pattern={briefing.sigilPattern}
                            color={sigilColor}
                            accessory={briefing.accessory}
                            size={128}
                            className="absolute inset-0 mix-blend-multiply dark:mix-blend-screen"
                        />
                    </div>
                </div>

                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-baseline gap-2">
                        <span className="text-xs font-semibold uppercase tracking-wider text-ink-soft dark:text-ink-soft-dark">
                            Briefing Temari
                        </span>
                        <span className="text-xs font-semibold text-ink dark:text-ink-dark">
                            {briefing.vibeEmoji} {briefing.vibeLabel}
                        </span>
                        {briefing.degraded && <DegradedChip />}
                    </div>
                    <p className="mt-2 text-lg font-semibold leading-snug tracking-tight text-ink dark:text-ink-dark sm:text-xl">
                        {briefing.headlineLine}
                    </p>
                    <p className="mt-1 text-sm leading-relaxed text-ink-soft dark:text-ink-soft-dark">
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
        </div>
    );
}

function vibeBackground(state: string): string {
    switch (state) {
        case 'pumped':
        case 'fresh':
        case 'bouncy':
            return 'bg-gradient-to-br from-brand-50 to-brand-100 dark:from-brand-900/40 dark:to-brand-800/40';
        case 'cooked':
        case 'stretched_thin':
            return 'bg-gradient-to-br from-mood-cooked/10 to-mood-cooked/20 dark:from-mood-cooked/30 dark:to-mood-cooked/20';
        case 'worn_down':
            return 'bg-gradient-to-br from-accent-50 to-accent-100 dark:from-accent-900/40 dark:to-accent-800/40';
        case 'hibernating':
            return 'bg-gradient-to-br from-surface to-mood-hibernate/15 dark:from-surface-dark-elev dark:to-mood-hibernate/30';
        default:
            return 'bg-gradient-to-br from-mood-spinning/10 to-mood-spinning/15 dark:from-mood-spinning/30 dark:to-mood-spinning/20';
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
