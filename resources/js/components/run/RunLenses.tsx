import { router, usePage } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';

import type { AnalysisPayload, SharedProps } from '@/types/inertia';

import AnalysisStatus from '@/components/temari/AnalysisStatus';
import FaceIcon from '@/components/temari/FaceIcon';
import Chip from '@/components/ui/Chip';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import { triggerAnalysis } from '@/hooks/useAnalysisTrigger';
import {
    cooldownAriaLabel,
    useCooldownCountdown,
} from '@/hooks/useCooldownCountdown';
import { cn } from '@/lib/cn';
import { formatDurationHMS } from '@/lib/pace';
import { renderBold } from '@/lib/richText';

/**
 * One anchored, falsifiable observation about this run. `anchor` names the
 * exact split/zone/metric it describes (validated server-side against the
 * run's own data before this ever reaches the client — see
 * RunInsightNarrator); it drives nothing in this component beyond a React
 * key. `value`/`delta` are optional headline figures shown next to the text.
 */
interface RunInsightClaim {
    anchor: string;
    text: string;
    value?: string | null;
    delta?: string | null;
}

interface RunLensesProps {
    /** The post-run story (PostRunSpeech). */
    story: AnalysisPayload;
    /** The adaptive claims block (RunInsight) — a variable-length list of anchored observations. */
    insight: AnalysisPayload;
    /**
     * This run is the head of the per-activity narration chain (the latest run).
     * Per-activity narration is connected + chained: only the head may
     * regenerate, so the "Reread" control shows on the head only.
     * Historical runs are resume-only via the per-block chain actions.
     */
    isChainHead?: boolean;
    inertiaReloadProps?: string[];
    className?: string;
}

const DEFAULT_RELOAD_PROPS = ['speechAnalysis', 'runInsight'];

function rereadLabel(pending: boolean, cooldownRemaining: number): string {
    if (pending) {
        return 'Rereading…';
    }
    if (cooldownRemaining > 0) {
        return `Next in ${formatDurationHMS(cooldownRemaining)}`;
    }
    return 'Reread';
}

/**
 * Parse the run-insight block's JSON-encoded claims list. Malformed content
 * (should never happen — the server only ever writes its own JSON) renders
 * as no claims rather than throwing.
 */
function parseClaims(content: string): RunInsightClaim[] {
    try {
        const parsed: unknown = JSON.parse(content);
        return Array.isArray(parsed) ? (parsed as RunInsightClaim[]) : [];
    } catch {
        return [];
    }
}

/**
 * Whether the insight block has something to show. A block that is Done but
 * whose claims all failed the server-side anchor check decodes to an empty
 * list — render nothing for it, the same way a Pending block renders nothing,
 * rather than an empty half-card. Any other status defers to {@link AnalysisStatus}.
 */
function insightHasContent(insight: AnalysisPayload): boolean {
    if (insight.status !== 'done' || insight.content === null) {
        return true;
    }
    return parseClaims(insight.content).length > 0;
}

function ClaimLine({ claim }: Readonly<{ claim: RunInsightClaim }>) {
    return (
        <div className="flex flex-col gap-1.5">
            <p className="font-serif text-quote-sm italic leading-relaxed text-foreground">
                {renderBold(claim.text)}
            </p>
            {(claim.value ?? claim.delta) && (
                <div className="flex flex-wrap items-center gap-1.5">
                    {claim.value && <Chip tone="neutral">{claim.value}</Chip>}
                    {claim.delta && <Chip tone="horizon">{claim.delta}</Chip>}
                </div>
            )}
        </div>
    );
}

function LensLabel({
    icon,
    children,
}: Readonly<{ icon: string; children: string }>) {
    return (
        <div className="mb-2 flex items-center gap-1.5">
            <Icon icon={icon} width={12} height={12} aria-hidden />
            <Eyebrow token="micro" tone="icon-accent" as="span">
                {children}
            </Eyebrow>
        </div>
    );
}

/**
 * "What Temari says" — the run's story and what stood out, in one voice card,
 * as the prototype's `RunLenses` draws them. Two `Analysis` rows behind one
 * surface, separated by a hairline rather than split into two cards.
 */
export default function RunLenses({
    story,
    insight,
    isChainHead = false,
    inertiaReloadProps = DEFAULT_RELOAD_PROPS,
    className,
}: Readonly<RunLensesProps>) {
    const [bulkPending, setBulkPending] = useState(false);
    const paused = usePage<SharedProps>().props.aiPaused ?? false;

    const lenses = useMemo(() => [story, insight], [story, insight]);

    // The reread control respects the same per-row cooldown the server enforces:
    // it stays disabled until the longest-cooling lens unlocks. Lenses finish
    // within seconds of each other, so the max is a faithful shared countdown.
    const cooldownRemaining = useCooldownCountdown(
        Math.max(...lenses.map((a) => a.retry_after_seconds ?? 0)) || null,
    );
    const cooling = cooldownRemaining > 0;
    const showInsight = insightHasContent(insight);

    const triggerAll = useCallback(async () => {
        if (bulkPending || cooling) return;
        setBulkPending(true);
        await Promise.allSettled(lenses.map((a) => triggerAnalysis(a)));
        router.reload({ only: inertiaReloadProps });
        setBulkPending(false);
    }, [bulkPending, cooling, lenses, inertiaReloadProps]);

    return (
        <section className={className}>
            <header className="mb-3 flex items-center gap-3">
                <FaceIcon size={40} />
                <div className="min-w-0 flex-1">
                    <h2 className="font-serif text-quote-md italic text-foreground">
                        What Temari says
                    </h2>
                    <p className="mt-0.5 font-sans text-xs text-text-3">
                        The story of this run, and what stood out.
                    </p>
                </div>
            </header>

            <Card tone="narration" padding="hero">
                <LensLabel icon="mdi:chat-outline">
                    This run&apos;s story
                </LensLabel>
                <AnalysisStatus
                    analysis={story}
                    inertiaReloadProps={inertiaReloadProps}
                    chained
                    isChainHead={isChainHead}
                    allowReanalyze={!isChainHead}
                    showTimestamp={false}
                    renderContent={(text) => (
                        <p className="font-serif text-quote-sm italic leading-relaxed text-foreground">
                            {renderBold(text)}
                        </p>
                    )}
                />

                {showInsight && (
                    <>
                        <div
                            aria-hidden
                            className="my-3.5 h-px bg-border-strong"
                        />
                        <LensLabel icon="mdi:lightbulb-on-outline">
                            What stood out
                        </LensLabel>
                        <AnalysisStatus
                            analysis={insight}
                            inertiaReloadProps={inertiaReloadProps}
                            chained
                            isChainHead={isChainHead}
                            allowReanalyze={!isChainHead}
                            showTimestamp={false}
                            renderContent={(text) => (
                                <div className="flex flex-col gap-2.5">
                                    {parseClaims(text).map((claim) => (
                                        <ClaimLine
                                            key={claim.anchor}
                                            claim={claim}
                                        />
                                    ))}
                                </div>
                            )}
                        />
                    </>
                )}

                {/* Regenerate is head-only (chained kind); historical runs
                    resume per-block instead. */}
                {isChainHead && !paused && (
                    <div className="mt-3 flex justify-end">
                        <button
                            type="button"
                            onClick={triggerAll}
                            disabled={bulkPending || cooling}
                            aria-label={cooldownAriaLabel(
                                cooldownRemaining,
                                'rereading all',
                            )}
                            className="focus-ring pressable inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-1.5 text-label-micro text-text-2 transition hover:text-foreground disabled:cursor-not-allowed disabled:opacity-70"
                        >
                            <Icon
                                icon={
                                    cooling
                                        ? 'mdi:clock-outline'
                                        : 'mdi:refresh'
                                }
                                width={12}
                                height={12}
                                className={cn(bulkPending && 'animate-spin')}
                                aria-hidden
                            />
                            {rereadLabel(bulkPending, cooldownRemaining)}
                        </button>
                    </div>
                )}
            </Card>
        </section>
    );
}
