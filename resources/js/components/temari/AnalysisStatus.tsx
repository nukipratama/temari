import { Icon } from '@iconify/react';
import { usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

import type { AnalysisPayload, SharedProps } from '@/types/inertia';

import {
    RATE_LIMITED_ERROR,
    useAnalysisTrigger,
} from '@/hooks/useAnalysisTrigger';
import {
    cooldownAriaLabel,
    useCooldownCountdown,
} from '@/hooks/useCooldownCountdown';
import { formatDurationHMS, formatRelativeId } from '@/lib/pace';
import { renderBold } from '@/lib/richText';

import UnavailableNote from './UnavailableNote';

export type AnalysisStatusSize = 'sm' | 'md';

/**
 * A done block is stale when it is zone-dependent (per the server-provided
 * `is_zone_dependent` flag) and was generated strictly before the user's zones
 * last changed. Newer blocks (and blocks with no `generated_at` or no recorded
 * zone change) auto-clear.
 */
function hasStaleZones(
    isZoneDependent: boolean | undefined,
    generatedAt: string | null | undefined,
    hrZonesChangedAt: string | null | undefined,
): boolean {
    if (!isZoneDependent || !generatedAt || !hrZonesChangedAt) {
        return false;
    }

    return (
        new Date(generatedAt).getTime() < new Date(hrZonesChangedAt).getTime()
    );
}

function StaleZonesBadge() {
    return (
        <span className="inline-flex items-center self-start gap-1 rounded-full bg-horizon/15 px-2 py-0.5 text-xs text-ember-deep">
            <Icon icon="mdi:heart-pulse" aria-hidden />
            <span>calculated with old zones</span>
        </span>
    );
}

interface Props {
    analysis: AnalysisPayload;
    inertiaReloadProps?: string[];
    size?: AnalysisStatusSize;
    /** Render the LLM content. Receives the resolved narrative string. */
    renderContent?: (content: string) => ReactNode;
    /** Whether to show the manual trigger button when status is `done`. */
    allowReanalyze?: boolean;
    /**
     * The in-progress period (week or month): its recap waits for the scheduler,
     * so the manual trigger is suppressed and the empty state reads "not
     * available yet". The wording is set via {@link awaitingScheduleLabel}.
     */
    awaitingSchedule?: boolean;
    /** Empty-state copy shown when {@link awaitingSchedule}. Defaults to the weekly wording. */
    awaitingScheduleLabel?: string;
    /** Whether to render the "Dibuat …" relative timestamp when status is `done`. */
    showTimestamp?: boolean;
    /** Use cream-tinted colours for non-done states when rendered on a dark sky panel. */
    onSky?: boolean;
    /**
     * This block belongs to a connected + chained narration kind. The trigger
     * still POSTs to this row, but the server resumes the chain from the
     * earliest unfilled link instead of narrating this row in isolation. The
     * "Try again" / "Ask Temari to read it" actions on failed/pending links stay
     * (they resume the chain forward), but "Reread" (regenerate of a Done
     * block) is shown only on the chain head — see {@link isChainHead}.
     */
    chained?: boolean;
    /**
     * The latest item in its chain. Only the head may regenerate (`Reread`),
     * because re-narrating a mid-history Done block would desync every later
     * block that referenced its old narrative. Ignored unless `chained`.
     */
    isChainHead?: boolean;
}

const TEXT_SIZE: Record<AnalysisStatusSize, string> = {
    sm: 'text-sm leading-relaxed',
    md: 'text-base leading-relaxed',
};

/** Widths of the stacked skeleton bars shown while a block is queued/processing. */
const SKELETON_WIDTHS = ['w-full', 'w-[70%]', 'w-[85%]'];

function RateLimitedNote() {
    return (
        <span className="text-xs text-horizon-deep">
            Easy there, Temari&apos;s overwhelmed. Try again in a bit.
        </span>
    );
}

export default function AnalysisStatus({
    analysis,
    inertiaReloadProps = [],
    size = 'md',
    renderContent,
    allowReanalyze = true,
    awaitingSchedule = false,
    awaitingScheduleLabel = "This week's recap isn't available yet.",
    showTimestamp = true,
    onSky = false,
    chained = false,
    isChainHead = false,
}: Readonly<Props>) {
    const {
        status,
        pending,
        error,
        retryAfterSeconds,
        pollingRetired,
        paused,
        trigger,
    } = useAnalysisTrigger(analysis, inertiaReloadProps);
    const canTrigger = allowReanalyze && !awaitingSchedule && !paused;
    // A Done block may regenerate ("Reread") in standalone mode, but in a
    // chain only the head may, so regenerating mid-history can't desync later
    // links. Resume actions on failed/pending links stay regardless.
    const canRegenerate = canTrigger && (!chained || isChainHead);
    const { hrZonesChangedAt } = usePage<SharedProps>().props;
    const effectiveStatus = pending ? 'queued' : status;
    const content = analysis.content;
    const attempts = analysis.attempts ?? 0;
    const cooldownRemaining = useCooldownCountdown(retryAfterSeconds);
    const rateLimited = error === RATE_LIMITED_ERROR;

    if (effectiveStatus === 'done' && content !== null) {
        const cooling = cooldownRemaining > 0;
        const staleZones = hasStaleZones(
            analysis.is_zone_dependent,
            analysis.generated_at,
            hrZonesChangedAt,
        );
        return (
            <div className="flex flex-col gap-1">
                <div
                    className={`${TEXT_SIZE[size]} whitespace-pre-line text-ink`}
                >
                    {renderContent
                        ? renderContent(content)
                        : renderBold(content)}
                </div>
                {staleZones && <StaleZonesBadge />}
                {showTimestamp && analysis.generated_at && (
                    <span
                        className={`text-xs ${onSky ? 'text-ink-on-sky' : 'text-ink-3'}`}
                    >
                        Generated {formatRelativeId(analysis.generated_at)}
                    </span>
                )}
                {canRegenerate && (
                    <button
                        type="button"
                        onClick={trigger}
                        disabled={cooling || pending}
                        aria-label={cooldownAriaLabel(
                            cooldownRemaining,
                            'reread',
                        )}
                        className={`focus-ring rounded inline-flex items-center self-start gap-1 text-xs transition-colors disabled:cursor-not-allowed disabled:opacity-60 ${onSky ? 'text-ink-on-sky hover:text-cream disabled:hover:text-ink-on-sky' : 'text-ink-3 hover:text-leaf-deep disabled:hover:text-ink-3'}`}
                    >
                        <Icon icon="mdi:auto-awesome" aria-hidden />
                        <span>
                            {cooling
                                ? formatDurationHMS(cooldownRemaining)
                                : 'Reread'}
                        </span>
                    </button>
                )}
                {rateLimited && <RateLimitedNote />}
            </div>
        );
    }

    if (effectiveStatus === 'queued' || effectiveStatus === 'processing') {
        // Polling gave up without the block settling: drop the fake "working"
        // skeleton for an honest, quiet reload affordance.
        if (pollingRetired && !pending) {
            return (
                <div className="flex flex-col gap-1.5">
                    <span
                        className={`inline-flex items-center gap-1.5 text-xs ${onSky ? 'text-ink-on-sky' : 'text-ink-2'}`}
                    >
                        <Icon icon="mdi:clock-outline" aria-hidden />
                        <span>Still processing, check back in a bit.</span>
                    </span>
                </div>
            );
        }
        const skeletonBg = onSky ? 'skeleton-on-sky' : 'skeleton';
        return (
            <div
                className={`flex flex-col gap-3 ${TEXT_SIZE[size]}`}
                role="status"
                aria-live="polite"
            >
                <span className="sr-only">Temari&apos;s thinking it over…</span>
                <div className="flex flex-col gap-1.5">
                    {SKELETON_WIDTHS.map((width) => (
                        <div
                            key={width}
                            className={`h-[1.625em] rounded ${width} ${skeletonBg}`}
                            aria-hidden
                        />
                    ))}
                </div>
                {attempts > 1 && (
                    <span
                        className={`text-xs ${onSky ? 'text-ink-on-sky' : 'text-ink-3'}`}
                    >
                        Attempt {attempts}
                    </span>
                )}
            </div>
        );
    }

    if (effectiveStatus === 'failed') {
        return (
            <div className="flex flex-col gap-1.5">
                <UnavailableNote size={size} />
                {rateLimited && <RateLimitedNote />}
                {canTrigger && (
                    <button
                        type="button"
                        onClick={trigger}
                        disabled={pending}
                        className="focus-ring rounded inline-flex items-center self-start gap-1 text-xs text-leaf-deep hover:text-ink transition-colors disabled:opacity-50"
                    >
                        <Icon icon="mdi:auto-awesome" aria-hidden />
                        <span>Try again</span>
                    </button>
                )}
            </div>
        );
    }

    // A plain Pending block (synced but not narrated yet, from any entrypoint)
    // renders nothing — the chain/self-heal/backfill sweep reaches it on its
    // own, and a visible "not narrated yet" state reads as broken. A window-
    // gated block (awaitingSchedule) is a different, intentional case — it
    // explains why the recap isn't out yet, so it keeps its own message.
    if (!awaitingSchedule) {
        return null;
    }

    return (
        <div className="flex flex-col gap-1.5">
            <span
                className={`inline-flex items-center gap-1.5 text-xs ${onSky ? 'text-ink-on-sky' : 'text-ink-2'}`}
            >
                <Icon icon="mdi:clock-outline" aria-hidden />
                <span>{awaitingScheduleLabel}</span>
            </span>
        </div>
    );
}
