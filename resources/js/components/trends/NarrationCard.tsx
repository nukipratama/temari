import { RefreshCw, Sparkles } from 'lucide-react';

import type { AnalysisPayload } from '@/types/inertia';

import AnalysisStatus from '@/components/temari/AnalysisStatus';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import Skeleton from '@/components/ui/Skeleton';
import { useAnalysisTrigger } from '@/hooks/useAnalysisTrigger';
import { useCooldownCountdown } from '@/hooks/useCooldownCountdown';
import { formatDurationHMS } from '@/lib/pace';

interface NarrationCardProps {
    analysis: AnalysisPayload;
    className?: string;
}

const EYEBROW_LABEL = "Temari's read · last 7 days";

/**
 * Splits the narrator's "{title}\n\n{description}" shape (see
 * TrendReadNarrator::generate()) into a bold headline and a supporting
 * paragraph. Falls back to rendering the whole string as the title when a
 * rule-based fallback or an older row doesn't carry the blank-line split.
 */
function splitContent(content: string): { title: string; description: string } {
    const [title, ...rest] = content.split('\n\n');
    return { title: title.trim(), description: rest.join('\n\n').trim() };
}

/**
 * The verdict card, the only place on Trends a call is made — everything
 * else on the page is evidence. Any state short of a written verdict (a
 * plain Pending row, still queued, or Failed) renders the same honest empty
 * card: every number on the page is real and current, only the sentence is
 * missing, with the same per-block "try again" every other narrated block
 * carries. Direction A (#967) deliberately does not distinguish those
 * states further — a mid-flight skeleton would just be more silence.
 */
export default function NarrationCard({
    analysis,
    className,
}: Readonly<NarrationCardProps>) {
    const { status, pending, retryAfterSeconds, trigger } = useAnalysisTrigger(
        analysis,
        ['narration'],
    );
    const effectiveStatus = pending ? 'queued' : status;
    const cooldownRemaining = useCooldownCountdown(retryAfterSeconds);
    const cooling = cooldownRemaining > 0;

    if (effectiveStatus === 'done' && analysis.content !== null) {
        return (
            <Card as="section" tone="narration" className={className}>
                <Eyebrow
                    token="micro"
                    className="mb-1.5 flex items-center gap-1.5 text-icon-accent"
                >
                    <Icon icon={Sparkles} className="size-3" aria-hidden />
                    {EYEBROW_LABEL}
                </Eyebrow>
                <AnalysisStatus
                    analysis={analysis}
                    inertiaReloadProps={['narration']}
                    awaitingSchedule={false}
                    renderContent={(content) => {
                        const { title, description } = splitContent(content);
                        return (
                            <>
                                <p className="narration font-semibold">
                                    {title}
                                </p>
                                {description !== '' && (
                                    <p className="narration-dense mt-1.5">
                                        {description}
                                    </p>
                                )}
                            </>
                        );
                    }}
                />
            </Card>
        );
    }

    return (
        <Card as="section" tone="empty" className={className}>
            <div className="flex items-center justify-between gap-3">
                <Eyebrow
                    token="micro"
                    className="flex items-center gap-1.5 text-text-2"
                >
                    <Icon icon={Sparkles} className="size-3" aria-hidden />
                    {EYEBROW_LABEL}
                </Eyebrow>
                <button
                    type="button"
                    onClick={trigger}
                    disabled={pending || cooling}
                    className="focus-ring pad-chip text-label-micro pressable inline-flex flex-none items-center gap-1 rounded-full bg-muted text-foreground transition-colors hover:bg-accent disabled:pointer-events-none disabled:opacity-60"
                >
                    <Icon icon={RefreshCw} className="size-3" aria-hidden />
                    <span>
                        {cooling
                            ? `next in ${formatDurationHMS(cooldownRemaining)}`
                            : 'try again'}
                    </span>
                </button>
            </div>
            <p className="prose mt-2.5 text-sm leading-relaxed text-text-2">
                not written yet: every number on this page is here and current,
                only temari&apos;s sentence is missing.
            </p>
            <div className="mt-3 flex flex-col gap-1.5" aria-hidden>
                <Skeleton className="h-2.5 w-3/4" />
                <Skeleton className="h-2.5 w-1/2" />
            </div>
        </Card>
    );
}

export { splitContent };
