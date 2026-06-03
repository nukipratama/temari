import { motion } from 'framer-motion';
import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import { useAnalysisTrigger } from '@/hooks/useAnalysisTrigger';
import { useCooldownCountdown } from '@/hooks/useCooldownCountdown';
import { fadeInUp } from '@/lib/motion';
import { formatDurationHMS, formatIdDate } from '@/lib/pace';
import { renderBold } from '@/lib/richText';
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
    firstName?: string;
    className?: string;
}

export default function BriefingCard({
    briefing,
    firstName,
    className,
}: Readonly<BriefingCardProps>) {
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
                'relative flex h-full flex-col rounded-2xl border border-line bg-surface-warm p-4 pb-12 shadow-sm sm:p-5 sm:pb-12',
                'border-l-[3px]',
                ruleClass,
                className,
            )}
        >
            <div className="flex flex-1 flex-col gap-4 sm:flex-row sm:items-stretch sm:gap-6">
                {/* LEFT 60% — greeting + narrator */}
                <div className="min-w-0 sm:basis-3/5">
                    <p className="font-mono text-xs font-bold uppercase tracking-wider text-ink-2">
                        {formatIdDate(new Date().toISOString(), 'long')}
                    </p>
                    {firstName !== undefined && firstName !== '' && (
                        <h1 className="mt-1 text-2xl font-semibold tracking-tight text-ink sm:text-3xl">
                            Halo, {firstName}!
                        </h1>
                    )}
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-leaf/15 px-3 py-1 text-xs font-semibold text-leaf-deep">
                            <span aria-hidden>{briefing.vibeEmoji}</span>
                            {briefing.vibeLabel}
                        </span>
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

                    <div className="mt-3">
                        {bothDone ? (
                            <BriefingDone headline={briefing.headline} suggestion={briefing.suggestion} />
                        ) : (
                            <BriefingPending headline={briefing.headline} />
                        )}
                    </div>
                </div>

                {/* RIGHT 40% — mascot only */}
                <div className="flex flex-col items-center justify-center sm:basis-2/5">
                    <div className="relative">
                        <TemariMascot
                            mood={briefing.mood}
                            sizeClass="h-48 w-48 sm:h-56 sm:w-56 lg:h-60 lg:w-60"
                            idle="mood"
                            gazeTracking
                            ornaments
                            aria-label={`Temari, mood ${briefing.mood}`}
                        />
                        <TemariPeek lines={PEEK_LINES} />
                    </div>
                </div>
            </div>

            {/* Full-width speech bubble at the bottom (above the absolute footer button) */}
            {briefing.mascotVoice.status === 'done' && briefing.mascotVoice.content !== null && briefing.mascotVoice.content !== '' && (
                <div className="relative mt-4 rounded-2xl border border-leaf/25 bg-leaf/10 px-3 py-2.5 pl-9 text-sm italic leading-snug text-ink">
                    <Icon
                        icon="mdi:comment-quote-outline"
                        width={16}
                        height={16}
                        aria-hidden
                        className="absolute left-2.5 top-2.5 text-leaf-deep"
                    />
                    {briefing.mascotVoice.content}
                </div>
            )}

            <div className="absolute bottom-3 right-3 z-10 sm:bottom-4 sm:right-4">
                <BriefingFooterButton headline={briefing.headline} />
            </div>
        </motion.div>
    );
}

function BriefingFooterButton({ headline }: Readonly<{ headline: AnalysisPayload }>) {
    const { status, pending, error, retryAfterSeconds, trigger } = useAnalysisTrigger(headline, ['briefing']);
    const remaining = useCooldownCountdown(retryAfterSeconds);

    const cooling = remaining > 0;
    const effective = pending ? 'queued' : status;

    if (effective === 'queued' || effective === 'processing') {
        return (
            <span className="inline-flex items-center gap-1.5 rounded-full bg-surface-sunken/90 px-3 py-1.5 text-xs text-ink-3 backdrop-blur-sm">
                <Icon icon="mdi:loading" className="animate-spin" aria-hidden />
                <span>Lagi dipikirin Temari…</span>
            </span>
        );
    }

    if (effective === 'failed') {
        return (
            <button
                type="button"
                onClick={trigger}
                disabled={pending}
                className="focus-ring inline-flex items-center gap-1 rounded-full bg-leaf-deep px-3 py-1 text-xs font-semibold text-cream shadow-sm transition hover:opacity-90 disabled:opacity-50"
            >
                <Icon icon="mdi:reload" aria-hidden />
                <span>Coba lagi</span>
            </button>
        );
    }

    if (effective === 'pending') {
        return (
            <button
                type="button"
                onClick={trigger}
                disabled={pending}
                className="focus-ring inline-flex items-center gap-1 rounded-full bg-leaf-deep px-3 py-1 text-xs font-semibold text-cream shadow-sm transition hover:opacity-90 disabled:opacity-50"
            >
                <Icon icon="mdi:auto-fix" aria-hidden />
                <span>Minta Temari bacain</span>
            </button>
        );
    }

    return (
        <button
            type="button"
            onClick={trigger}
            disabled={cooling || pending}
            aria-label={error ?? undefined}
            className="focus-ring inline-flex items-center gap-1 rounded-full bg-surface-sunken/80 px-2.5 py-1 text-xs text-ink-3 backdrop-blur-sm transition hover:text-leaf-deep disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:text-ink-3"
        >
            <Icon icon="mdi:refresh" aria-hidden />
            <span>{cooling ? `Tunggu ${formatDurationHMS(remaining)} ya` : 'Baca ulang'}</span>
        </button>
    );
}

function BriefingDone({ headline, suggestion }: Readonly<{ headline: AnalysisPayload; suggestion: AnalysisPayload }>) {
    return (
        <div className="space-y-2">
            <AnalysisStatus
                analysis={headline}
                inertiaReloadProps={['briefing']}
                size="md"
                allowReanalyze={false}
                showTimestamp={false}
                renderContent={(content) => (
                    <p className="text-lg font-semibold leading-snug tracking-tight text-ink">{renderBold(content)}</p>
                )}
            />
            <AnalysisStatus
                analysis={suggestion}
                inertiaReloadProps={['briefing']}
                size="sm"
                allowReanalyze={false}
                showTimestamp={false}
                renderContent={(content) => (
                    <p className="text-sm leading-snug text-ink-2">{renderBold(content)}</p>
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
            allowReanalyze={false}
            showTimestamp={false}
            renderContent={(content) => (
                <p className="text-lg font-semibold leading-snug tracking-tight text-ink">{renderBold(content)}</p>
            )}
        />
    );
}

const VIBE_RULES: Record<string, string> = {
    pumped: 'border-l-leaf',
    fresh: 'border-l-leaf',
    bouncy: 'border-l-leaf',
    cooked: 'border-l-mood-lemes',
    stretched_thin: 'border-l-mood-lemes',
    worn_down: 'border-l-horizon',
    hibernating: 'border-l-mood-adem',
};

function vibeLeftRule(state: string): string {
    return VIBE_RULES[state] ?? 'border-l-mood-mumet';
}

const RECOVERY_CHIP: Record<RecoveryTone, string> = {
    positive: 'bg-mood-enteng/15 text-mood-enteng',
    warning: 'bg-mood-nyala/15 text-mood-nyala',
    alert: 'bg-mood-lemes/15 text-mood-lemes',
    neutral: 'bg-surface-elev/70 text-ink',
};

function recoveryChipClass(tone: RecoveryTone): string {
    return RECOVERY_CHIP[tone];
}
