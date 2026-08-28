import { router, usePage } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';

import type { AnalysisPayload, SharedProps } from '@/types/inertia';

import AnalysisStatus from '@/components/temari/AnalysisStatus';
import Card from '@/components/ui/Card';
import Chip from '@/components/ui/Chip';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
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
    /** The post-run story (PostRunSpeech) — unchanged from before this slice. */
    story: AnalysisPayload;
    /** The adaptive claims block (RunInsight) — a variable-length list of anchored observations. */
    insight: AnalysisPayload;
    /**
     * This run is the head of the per-activity narration chain (the latest run).
     * Per-activity narration is connected + chained: only the head may
     * regenerate, so the "Reread all" control shows on the head only.
     * Historical runs are resume-only via the per-block chain actions.
     */
    isChainHead?: boolean;
    inertiaReloadProps?: string[];
    className?: string;
}

const DEFAULT_RELOAD_PROPS = ['speechAnalysis', 'runInsight'];

function bulkButtonLabel(pending: boolean, cooldownRemaining: number): string {
    if (pending) {
        return 'Rereading…';
    }
    if (cooldownRemaining > 0) {
        return formatDurationHMS(cooldownRemaining);
    }
    return 'Reread all';
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
 * rather than an empty card. Any other status defers to {@link AnalysisStatus}.
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
            <p className="font-sans text-quote-sm leading-relaxed text-foreground">
                {renderBold(claim.text)}
            </p>
            {(claim.value ?? claim.delta) && (
                <div className="flex flex-wrap items-center gap-1.5">
                    {claim.value && <Chip tone="sky">{claim.value}</Chip>}
                    {claim.delta && <Chip tone="neutral">{claim.delta}</Chip>}
                </div>
            )}
        </div>
    );
}

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

    // The bulk control respects the same per-row cooldown the server enforces:
    // it stays disabled until the longest-cooling lens unlocks. Lenses finish
    // within seconds of each other, so the max is a faithful shared countdown.
    const cooldownRemaining = useCooldownCountdown(
        Math.max(...lenses.map((a) => a.retry_after_seconds ?? 0)) || null,
    );
    const cooling = cooldownRemaining > 0;
    const bulkLabel = bulkButtonLabel(bulkPending, cooldownRemaining);

    const triggerAll = useCallback(async () => {
        if (bulkPending || cooling) return;
        setBulkPending(true);
        await Promise.allSettled(lenses.map((a) => triggerAnalysis(a)));
        router.reload({ only: inertiaReloadProps });
        setBulkPending(false);
    }, [bulkPending, cooling, lenses, inertiaReloadProps]);

    return (
        <div className={cn('flex flex-col gap-4', className)}>
            {/* Single re-analyze control. Regenerate is head-only (chained kind);
                historical runs resume per-block instead. */}
            {isChainHead && !paused && (
                <div className="flex justify-start">
                    <button
                        type="button"
                        onClick={triggerAll}
                        disabled={bulkPending || cooling}
                        aria-label={cooldownAriaLabel(
                            cooldownRemaining,
                            'rereading all',
                        )}
                        className="focus-ring rounded inline-flex items-center gap-1.5 text-label-micro text-text-2 transition hover:text-leaf-ink disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <Icon
                            icon={
                                bulkPending ? 'mdi:loading' : 'mdi:auto-awesome'
                            }
                            className={cn(bulkPending && 'animate-spin')}
                            aria-hidden
                        />
                        {bulkLabel}
                    </button>
                </div>
            )}

            <div className="flex flex-col gap-3.5">
                <Card
                    as="article"
                    padding="hero"
                    className="border-l-[3px] border-l-leaf"
                >
                    <div className="mb-2.5 flex items-center gap-2">
                        <Icon
                            icon="mdi:chat-outline"
                            width={14}
                            height={14}
                            aria-hidden
                            className="text-leaf-ink"
                        />
                        <Eyebrow token="micro" tone="ink-2">
                            This run&apos;s story
                        </Eyebrow>
                    </div>
                    <AnalysisStatus
                        analysis={story}
                        inertiaReloadProps={inertiaReloadProps}
                        chained
                        isChainHead={isChainHead}
                        allowReanalyze={!isChainHead}
                        showTimestamp={false}
                        renderContent={(text) => (
                            <p className="font-sans text-quote-sm leading-relaxed text-foreground">
                                {renderBold(text)}
                            </p>
                        )}
                    />
                </Card>

                {insightHasContent(insight) && (
                    <Card
                        as="article"
                        padding="hero"
                        className="border-l-[3px] border-l-ember"
                    >
                        <div className="mb-2.5 flex items-center gap-2">
                            <Icon
                                icon="mdi:lightbulb-on-outline"
                                width={14}
                                height={14}
                                aria-hidden
                                className="text-ember-ink"
                            />
                            <Eyebrow token="micro" tone="ink-2">
                                What stood out
                            </Eyebrow>
                        </div>
                        <AnalysisStatus
                            analysis={insight}
                            inertiaReloadProps={inertiaReloadProps}
                            chained
                            isChainHead={isChainHead}
                            allowReanalyze={!isChainHead}
                            showTimestamp={false}
                            renderContent={(text) => {
                                const claims = parseClaims(text);

                                return (
                                    <div className="flex flex-col gap-3">
                                        {claims.map((claim) => (
                                            <ClaimLine
                                                key={claim.anchor}
                                                claim={claim}
                                            />
                                        ))}
                                    </div>
                                );
                            }}
                        />
                    </Card>
                )}
            </div>
        </div>
    );
}
