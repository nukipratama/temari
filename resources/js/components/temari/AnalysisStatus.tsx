import { type ReactNode } from 'react';
import { Icon } from '@iconify/react';
import { usePage } from '@inertiajs/react';
import { RATE_LIMITED_ERROR, useAnalysisTrigger } from '@/hooks/useAnalysisTrigger';
import { cooldownAriaLabel, useCooldownCountdown } from '@/hooks/useCooldownCountdown';
import { formatDurationHMS, formatRelativeId } from '@/lib/pace';
import { renderBold } from '@/lib/richText';
import type { AnalysisPayload, SharedProps } from '@/types/inertia';
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

    return new Date(generatedAt).getTime() < new Date(hrZonesChangedAt).getTime();
}

function StaleZonesBadge() {
    return (
        <span className="inline-flex items-center self-start gap-1 rounded-full bg-horizon/15 px-2 py-0.5 text-xs text-ember-deep">
            <Icon icon="mdi:heart-pulse" aria-hidden />
            <span>dihitung dengan zona lama</span>
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
     * so the manual trigger is suppressed and the empty state reads "belum
     * tersedia". The wording is set via {@link awaitingScheduleLabel}.
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
     * "Coba lagi" / "Minta Temari bacain" actions on failed/pending links stay
     * (they resume the chain forward), but "Baca ulang" (regenerate of a Done
     * block) is shown only on the chain head — see {@link isChainHead}.
     */
    chained?: boolean;
    /**
     * The latest item in its chain. Only the head may regenerate (`Baca ulang`),
     * because re-narrating a mid-history Done block would desync every later
     * block that referenced its old narrative. Ignored unless `chained`.
     */
    isChainHead?: boolean;
}

const TEXT_SIZE: Record<AnalysisStatusSize, string> = {
    sm: 'text-sm leading-relaxed',
    md: 'text-base leading-relaxed',
};

function RateLimitedNote() {
    return (
        <span className="text-xs text-horizon-deep">
            Pelan-pelan, Temari kewalahan. Coba lagi sebentar ya.
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
    awaitingScheduleLabel = 'Rekap minggu ini belum tersedia.',
    showTimestamp = true,
    onSky = false,
    chained = false,
    isChainHead = false,
}: Readonly<Props>) {
    const { status, pending, error, retryAfterSeconds, trigger } = useAnalysisTrigger(analysis, inertiaReloadProps);
    const canTrigger = allowReanalyze && !awaitingSchedule;
    // A Done block may regenerate ("Baca ulang") in standalone mode, but in a
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
        const staleZones = hasStaleZones(analysis.is_zone_dependent, analysis.generated_at, hrZonesChangedAt);
        return (
            <div className="flex flex-col gap-1">
                <div className={`${TEXT_SIZE[size]} whitespace-pre-line text-ink`}>
                    {renderContent ? renderContent(content) : renderBold(content)}
                </div>
                {staleZones && <StaleZonesBadge />}
                {showTimestamp && analysis.generated_at && (
                    <span className="text-xs text-ink-3">
                        Dibuat {formatRelativeId(analysis.generated_at)}
                    </span>
                )}
                {canRegenerate && (
                    <button
                        type="button"
                        onClick={trigger}
                        disabled={cooling || pending}
                        aria-label={cooldownAriaLabel(cooldownRemaining, 'baca ulang')}
                        className="focus-ring rounded inline-flex items-center self-start gap-1 text-xs text-ink-3 hover:text-leaf-deep transition-colors disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:text-ink-3"
                    >
                        <Icon icon="mdi:auto-awesome" aria-hidden />
                        <span>{cooling ? formatDurationHMS(cooldownRemaining) : 'Baca ulang'}</span>
                    </button>
                )}
                {rateLimited && <RateLimitedNote />}
            </div>
        );
    }

    if (effectiveStatus === 'queued' || effectiveStatus === 'processing') {
        return (
            <span
                className={`inline-flex items-center gap-2 rounded-full text-xs px-3 py-1.5 ${onSky ? 'bg-cream/10 text-cream/70' : 'bg-surface-sunken text-ink-2'}`}
                role="status"
                aria-live="polite"
            >
                <Icon icon="mdi:loading" className="animate-spin" aria-hidden />
                <span>
                    Lagi dipikirin Temari…
                    {attempts > 1 && ` (percobaan ${attempts})`}
                </span>
            </span>
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
                        <span>Coba lagi</span>
                    </button>
                )}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-1.5">
            <span className={`inline-flex items-center gap-1.5 text-xs ${onSky ? 'text-ink-on-sky' : 'text-ink-2'}`}>
                <Icon icon={awaitingSchedule ? 'mdi:clock-outline' : 'mdi:sparkles-outline'} aria-hidden />
                <span>{awaitingSchedule ? awaitingScheduleLabel : 'Belum dibaca Temari.'}</span>
            </span>
            {canTrigger && (
                <button
                    type="button"
                    onClick={trigger}
                    disabled={pending}
                    className="focus-ring inline-flex items-center self-start gap-1 rounded-full bg-leaf-deep text-cream text-xs px-3 py-1 font-semibold transition hover:opacity-90 disabled:opacity-50"
                >
                    <Icon icon="mdi:auto-awesome" aria-hidden />
                    <span>Minta Temari bacain</span>
                </button>
            )}
        </div>
    );
}
