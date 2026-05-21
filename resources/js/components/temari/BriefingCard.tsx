import { motion } from 'framer-motion';
import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';
import AnalysisStatus from './AnalysisStatus';
import TemariMascot from './TemariMascot';
import TemariPeek from './TemariPeek';
import type { AnalysisPayload, BriefingResult, RecoveryTone } from '@/types/inertia';

const PEEK_LINES = [
    'Sedang menunggu lari berikutnya',
    'Coba lihat pace minggu lalu, semakin halus',
    'Ingat istirahat ya, jangan dipaksa terus',
    'Form kamu sedang bagus, manfaatkan',
    'Ketuk aku untuk reaksi 🌀',
] as const;

interface BriefingCardProps {
    briefing: BriefingResult;
    className?: string;
}

export default function BriefingCard({ briefing, className }: Readonly<BriefingCardProps>) {
    const ruleClass = vibeLeftRule(briefing.vibeState);
    const recoveryClass = recoveryChipClass(briefing.recoveryTone);
    const bothDone =
        briefing.headline.status === 'done' &&
        briefing.suggestion.status === 'done' &&
        briefing.headline.content !== null &&
        briefing.suggestion.content !== null;

    return (
        <motion.div
            variants={fadeInUp}
            initial="hidden"
            animate="visible"
            className={cn(
                'flex h-full flex-col rounded-2xl border border-line bg-surface-warm p-4 shadow-sm sm:p-5',
                'border-l-[3px]',
                ruleClass,
                className,
            )}
        >
            <div className="flex flex-1 flex-col items-start gap-4 sm:flex-row sm:items-center sm:gap-6">
                <div className="relative shrink-0">
                    <TemariMascot
                        mood={briefing.mood}
                        sizeClass="h-52 w-52 sm:h-56 sm:w-56"
                        idle="mood"
                        gazeTracking
                        ornaments
                        aria-label={`Temari — mood ${briefing.mood}`}
                    />
                    <TemariPeek lines={PEEK_LINES} />
                </div>

                <div className="min-w-0 flex-1 self-center">
                    <div className="flex flex-wrap items-baseline gap-2">
                        <span className="text-xs font-semibold uppercase tracking-wider text-ink-meta">
                            Briefing Temari
                        </span>
                        <span className="text-xs font-semibold text-ink">
                            {briefing.vibeEmoji} {briefing.vibeLabel}
                        </span>
                    </div>

                    <div className="mt-2">
                        {bothDone ? (
                            <BriefingDone headline={briefing.headline} suggestion={briefing.suggestion} />
                        ) : (
                            <BriefingPending headline={briefing.headline} />
                        )}
                    </div>

                    <div className="mt-3 flex flex-wrap gap-2">
                        <span className={cn('inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold', recoveryClass)}>
                            <Icon icon="mdi:heart-pulse" width={14} height={14} aria-hidden />
                            {briefing.recoveryLabel}
                        </span>
                        {briefing.streakLabel !== null && (
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-surface-elev/70 px-3 py-1 text-xs font-semibold text-ink">
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

function BriefingDone({ headline, suggestion }: Readonly<{ headline: AnalysisPayload; suggestion: AnalysisPayload }>) {
    return (
        <div className="space-y-1">
            <AnalysisStatus
                analysis={headline}
                inertiaReloadProps={['briefing']}
                size="md"
                allowReanalyze={false}
                renderContent={(content) => (
                    <p className="text-lg font-semibold leading-snug tracking-tight text-ink">{content}</p>
                )}
            />
            <AnalysisStatus
                analysis={suggestion}
                inertiaReloadProps={['briefing']}
                size="sm"
                renderContent={(content) => (
                    <p className="text-sm leading-relaxed text-ink-soft">{content}</p>
                )}
            />
        </div>
    );
}

/**
 * When at least one side is still pending/queued/failed, render a single
 * `AnalysisStatus` on the headline so the user sees one CTA instead of two
 * stacked "Coba lagi" buttons. Triggering it kicks the shared LLM call that
 * fills both rows (the job-side cache de-dupes the Azure round-trip).
 */
function BriefingPending({ headline }: Readonly<{ headline: AnalysisPayload }>) {
    return (
        <AnalysisStatus
            analysis={headline}
            inertiaReloadProps={['briefing']}
            size="md"
            renderContent={(content) => (
                <p className="text-lg font-semibold leading-snug tracking-tight text-ink">{content}</p>
            )}
        />
    );
}

const VIBE_RULES: Record<string, string> = {
    pumped: 'border-l-brand-500',
    fresh: 'border-l-brand-500',
    bouncy: 'border-l-brand-500',
    cooked: 'border-l-mood-cooked',
    stretched_thin: 'border-l-mood-cooked',
    worn_down: 'border-l-accent-500',
    hibernating: 'border-l-mood-hibernate',
};

function vibeLeftRule(state: string): string {
    return VIBE_RULES[state] ?? 'border-l-mood-spinning';
}

const RECOVERY_CHIP: Record<RecoveryTone, string> = {
    positive: 'bg-mood-bouncy/15 text-mood-bouncy',
    warning: 'bg-mood-glow/15 text-mood-glow',
    alert: 'bg-mood-cooked/15 text-mood-cooked',
    neutral: 'bg-surface-elev/70 text-ink',
};

function recoveryChipClass(tone: RecoveryTone): string {
    return RECOVERY_CHIP[tone];
}
