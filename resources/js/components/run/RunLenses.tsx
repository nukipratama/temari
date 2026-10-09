import { router, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    Clock,
    Lightbulb,
    MessageCircle,
    RefreshCw,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

import type { AnalysisPayload, Mood, SharedProps } from '@/types/inertia';

import AnalysisStatus, {
    rendersNothing,
} from '@/components/temari/AnalysisStatus';
import TemariMascot, { writingPose } from '@/components/temari/TemariMascot';
import Chip from '@/components/ui/Chip';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon, IconComponent } from '@/components/ui/Icon';
import PillButton from '@/components/ui/PillButton';
import { triggerAnalysis } from '@/hooks/useAnalysisTrigger';
import {
    cooldownAriaLabel,
    useCooldownCountdown,
} from '@/hooks/useCooldownCountdown';
import { anchorLabel, revealAnchor } from '@/lib/anchors';
import { cn } from '@/lib/cn';
import { formatDurationHMS } from '@/lib/pace';
import { renderBold } from '@/lib/richText';
import { chipVariants } from '@/lib/variants';

/**
 * One anchored, falsifiable observation about this run. `anchor` names the
 * exact split/zone/metric it describes (validated server-side against the
 * run's own data before this ever reaches the client — see
 * RunInsightNarrator), and the claim carries a control that scrolls to
 * whichever component draws it. `value`/`delta` are optional headline figures
 * shown next to the text.
 */
interface RunInsightClaim {
    anchor: string;
    text: string;
    value?: string | null;
    delta?: string | null;
}

interface RunLensesProps {
    mood: Mood;
    /** The post-run story (PostRunSpeech). */
    story: AnalysisPayload;
    /** The adaptive claims block (RunInsight) — a variable-length list of anchored observations. */
    insight: AnalysisPayload;
    /**
     * The anchors this page actually draws, from {@link drawnRunAnchors}. A
     * claim whose anchor is not in here gets no control: the narrator validates
     * against readings no component renders.
     */
    drawnAnchors?: ReadonlySet<string>;
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
        return 'rereading…';
    }
    if (cooldownRemaining > 0) {
        return `next in ${formatDurationHMS(cooldownRemaining)}`;
    }
    return 'reread';
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

function storyHasContent(story: AnalysisPayload): boolean {
    return !rendersNothing(story.status, story.content);
}

/**
 * A Done insight whose claims all failed the server-side anchor check decodes
 * to an empty list, which counts as empty like a Pending block.
 */
function insightHasContent(insight: AnalysisPayload): boolean {
    if (rendersNothing(insight.status, insight.content)) {
        return false;
    }
    if (insight.status !== 'done' || insight.content === null) {
        return true;
    }
    return parseClaims(insight.content).length > 0;
}

function ClaimLine({
    claim,
    drawn,
}: Readonly<{ claim: RunInsightClaim; drawn: boolean }>) {
    const label = anchorLabel(claim.anchor);

    return (
        <div className="flex flex-col gap-1.5">
            <p className="narration">{renderBold(claim.text)}</p>
            {(claim.value ?? claim.delta ?? (drawn && label !== null)) && (
                <div className="flex flex-wrap items-center gap-1.5">
                    {claim.value && <Chip tone="neutral">{claim.value}</Chip>}
                    {claim.delta && <Chip tone="horizon">{claim.delta}</Chip>}
                    {drawn && label !== null && (
                        <button
                            type="button"
                            onClick={() => revealAnchor(claim.anchor)}
                            aria-label={`Show ${label} on this page`}
                            className={cn(
                                chipVariants({ tone: 'neutral' }),
                                "focus-ring relative transition-colors after:absolute after:-inset-3 after:content-[''] hover:text-foreground",
                            )}
                        >
                            <Icon
                                icon={ArrowDown}
                                width={10}
                                height={10}
                                aria-hidden
                            />
                            {label}
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}

function ClaimList({
    text,
    drawn,
}: Readonly<{ text: string; drawn: ReadonlySet<string> }>) {
    const claims = useMemo(() => parseClaims(text), [text]);

    return (
        <div className="flex flex-col gap-2.5">
            {claims.map((claim) => (
                <ClaimLine
                    key={claim.anchor}
                    claim={claim}
                    drawn={drawn.has(claim.anchor)}
                />
            ))}
        </div>
    );
}

function LensLabel({
    icon,
    children,
}: Readonly<{ icon: IconComponent; children: string }>) {
    return (
        <div className="mb-2 flex items-center gap-1.5">
            <Icon icon={icon} width={12} height={12} aria-hidden />
            <Eyebrow token="micro" tone="icon-accent" as="h3">
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
    mood,
    story,
    insight,
    isChainHead = false,
    inertiaReloadProps = DEFAULT_RELOAD_PROPS,
    drawnAnchors = new Set<string>(),
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
    const showStory = storyHasContent(story);
    const showInsight = insightHasContent(insight);

    const triggerAll = useCallback(async () => {
        if (bulkPending || cooling) return;
        setBulkPending(true);
        await Promise.allSettled(lenses.map((a) => triggerAnalysis(a)));
        router.reload({ only: inertiaReloadProps });
        setBulkPending(false);
    }, [bulkPending, cooling, lenses, inertiaReloadProps]);

    if (!showStory && !showInsight) {
        return null;
    }

    return (
        <section className={className}>
            <header className="flex items-center gap-3">
                <TemariMascot
                    pose={writingPose(mood, story, insight)}
                    size={40}
                />
                <div className="min-w-0 flex-1">
                    <Eyebrow as="h2" token="small" tone="ink-2">
                        What Temari says
                    </Eyebrow>
                    <p className="mt-0.5 text-sm text-text-2">
                        the story of this run, and what stood out.
                    </p>
                </div>
            </header>

            <div className="mt-3">
                {showStory && (
                    <>
                        <LensLabel icon={MessageCircle}>
                            This run&apos;s story
                        </LensLabel>
                        <AnalysisStatus
                            analysis={story}
                            inertiaReloadProps={inertiaReloadProps}
                            chained
                            isChainHead={isChainHead}
                            allowReanalyze={!isChainHead}
                            renderContent={(text) => (
                                <p className="narration">{renderBold(text)}</p>
                            )}
                        />
                    </>
                )}

                {showInsight && (
                    <div
                        className={cn(
                            showStory &&
                                'mt-3.5 border-t border-dashed border-border pt-3.5',
                        )}
                    >
                        <LensLabel icon={Lightbulb}>What stood out</LensLabel>
                        <AnalysisStatus
                            analysis={insight}
                            inertiaReloadProps={inertiaReloadProps}
                            chained
                            isChainHead={isChainHead}
                            allowReanalyze={!isChainHead}
                            renderContent={(text) => (
                                <ClaimList text={text} drawn={drawnAnchors} />
                            )}
                        />
                    </div>
                )}

                {/* Regenerate is head-only (chained kind); historical runs
                    resume per-block instead. */}
                {isChainHead && !paused && (
                    <div className="mt-3 flex justify-end">
                        <PillButton
                            onClick={triggerAll}
                            disabled={bulkPending || cooling}
                            aria-label={cooldownAriaLabel(
                                cooldownRemaining,
                                'rereading all',
                            )}
                            tone="muted"
                            size="xs"
                        >
                            <Icon
                                icon={cooling ? Clock : RefreshCw}
                                width={12}
                                height={12}
                                className={cn(bulkPending && 'animate-spin')}
                                aria-hidden
                            />
                            {rereadLabel(bulkPending, cooldownRemaining)}
                        </PillButton>
                    </div>
                )}
            </div>
        </section>
    );
}
