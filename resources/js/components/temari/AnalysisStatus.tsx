import { type ReactNode } from 'react';
import { Icon } from '@iconify/react';
import { usePage } from '@inertiajs/react';
import { RATE_LIMITED_ERROR, useAnalysisTrigger } from '@/hooks/useAnalysisTrigger';
import { useCooldownCountdown } from '@/hooks/useCooldownCountdown';
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
     * The in-progress week: its recap waits for the weekly scheduler, so the
     * manual trigger is suppressed and the empty state reads "belum tersedia".
     */
    awaitingSchedule?: boolean;
    /** Whether to render the "Dibuat …" relative timestamp when status is `done`. */
    showTimestamp?: boolean;
    /** Use cream-tinted colours for non-done states when rendered on a dark sky panel. */
    onSky?: boolean;
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
    showTimestamp = true,
    onSky = false,
}: Readonly<Props>) {
    const { status, pending, error, retryAfterSeconds, trigger } = useAnalysisTrigger(analysis, inertiaReloadProps);
    const canTrigger = allowReanalyze && !awaitingSchedule;
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
                {canTrigger && (
                    <button
                        type="button"
                        onClick={trigger}
                        disabled={cooling || pending}
                        className="focus-ring rounded inline-flex items-center self-start gap-1 text-xs text-ink-3 hover:text-leaf-deep transition-colors disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:text-ink-3"
                    >
                        <Icon icon="mdi:refresh" aria-hidden />
                        <span>
                            {cooling
                                ? `Tunggu ${formatDurationHMS(cooldownRemaining)} ya`
                                : 'Baca ulang'}
                        </span>
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
                        <Icon icon="mdi:reload" aria-hidden />
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
                <span>{awaitingSchedule ? 'Recap minggu ini belum tersedia.' : 'Belum dibaca Temari.'}</span>
            </span>
            {canTrigger && (
                <button
                    type="button"
                    onClick={trigger}
                    disabled={pending}
                    className="focus-ring inline-flex items-center self-start gap-1 rounded-full bg-leaf-deep text-cream text-xs px-3 py-1 font-semibold transition hover:opacity-90 disabled:opacity-50"
                >
                    <Icon icon="mdi:auto-fix" aria-hidden />
                    <span>Minta Temari bacain</span>
                </button>
            )}
        </div>
    );
}
