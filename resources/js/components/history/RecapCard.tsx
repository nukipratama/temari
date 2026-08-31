import type { ReactNode } from 'react';

import type { AnalysisPayload, Mood } from '@/types/inertia';

import SendNotificationButton from '@/components/SendNotificationButton';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import FaceIcon, { DARK_FACE } from '@/components/temari/FaceIcon';
import { useNotificationsReachable } from '@/hooks/useNotificationsReachable';
import { cn } from '@/lib/cn';
import { renderBold } from '@/lib/richText';

interface RecapCardProps {
    mood: Mood | null;
    analysis: AnalysisPayload;
    /**
     * Rule-based prose shown while the LLM narration hasn't filled the block.
     * Omit for a kind with no rule-based fallback (monthly has none) — the
     * block then shows only AnalysisStatus's own pending/failed state.
     */
    fallback?: string;
    /** The in-progress period: suppress the manual trigger until it closes. */
    awaitingSchedule?: boolean;
    /** Empty-state copy shown when awaitingSchedule. Defaults to AnalysisStatus's own weekly wording. */
    awaitingScheduleLabel?: string;
    /** Chained kind: retry resumes the chain, regenerate is head-only. */
    isChainHead?: boolean;
    inertiaReloadProps?: string[];
    /** Metric chips rendered under the narration line. */
    chips?: ReactNode;
    /** The "send this recap" affordance, only offered once narration is done. */
    notification?: { url: string; retryAfterSeconds: number | null } | null;
    size?: 'week' | 'month';
    className?: string;
}

const DEFAULT_RELOAD_PROPS = ['weeklySnapshots', 'historicalSnapshots'];

/**
 * Temari's narrative recap for a week or a month: a mood-ringed face next to
 * the narration, chips underneath, and a "send it" affordance once the block
 * is done. Shared shape for the Feed's weekly recap and the Calendar's monthly
 * recap so both read as the same feature at two grains.
 */
export default function RecapCard({
    mood,
    analysis,
    fallback,
    awaitingSchedule = false,
    awaitingScheduleLabel,
    isChainHead = false,
    inertiaReloadProps = DEFAULT_RELOAD_PROPS,
    chips,
    notification = null,
    size = 'week',
    className,
}: Readonly<RecapCardProps>) {
    const notificationsReachable = useNotificationsReachable();

    return (
        <div
            className={cn(
                'flex items-start gap-2.5 rounded-md border border-border-strong bg-card shadow-e1',
                size === 'week' ? 'p-3' : 'p-3.5',
                className,
            )}
        >
            <FaceIcon
                size={36}
                ring={
                    mood ? `var(--color-mood-${mood})` : 'var(--color-horizon)'
                }
                {...DARK_FACE}
            />
            <div className="min-w-0 flex-1">
                <AnalysisStatus
                    analysis={analysis}
                    inertiaReloadProps={inertiaReloadProps}
                    awaitingSchedule={awaitingSchedule}
                    awaitingScheduleLabel={awaitingScheduleLabel}
                    chained
                    isChainHead={isChainHead}
                    size="sm"
                    renderContent={(content) => (
                        <p className="m-0 font-serif text-xs leading-[1.45] text-foreground italic">
                            {renderBold(content)}
                        </p>
                    )}
                />
                {fallback && analysis.status !== 'done' && (
                    <p className="m-0 font-serif text-xs leading-[1.45] text-foreground italic">
                        {awaitingSchedule && (
                            <span className="font-semibold text-text-3">
                                For now:{' '}
                            </span>
                        )}
                        {fallback}
                    </p>
                )}
                {chips && (
                    <div className="mt-1.5 flex flex-wrap gap-1.5">{chips}</div>
                )}
                {notification && analysis.status === 'done' && (
                    <div className="mt-2">
                        <SendNotificationButton
                            url={notification.url}
                            retryAfterSeconds={notification.retryAfterSeconds}
                            reachable={notificationsReachable}
                        />
                    </div>
                )}
            </div>
        </div>
    );
}
