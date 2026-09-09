import type { ReactNode } from 'react';

import type { AnalysisPayload, Mood } from '@/types/inertia';

import SendNotificationButton from '@/components/SendNotificationButton';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import FaceIcon, { DARK_FACE } from '@/components/temari/FaceIcon';
import NarrationFlag from '@/components/temari/NarrationFlag';
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
    /** Metric chips: under the narration on phones, beside it from tablet up. */
    chips?: ReactNode;
    /** The "send this recap" affordance, only offered once narration is done. */
    notification?: { url: string; retryAfterSeconds: number | null } | null;
    size?: 'week' | 'month';
    className?: string;
}

const DEFAULT_RELOAD_PROPS = ['weeklySnapshots', 'historicalSnapshots'];

/**
 * Temari's narrative recap for a week or a month: a mood-ringed face next to
 * the narration, its chips (underneath on phones, beside the read from tablet
 * up so a short recap doesn't leave the card half empty), and a "send it"
 * affordance once the block is done. Shared shape for the Feed's weekly recap and the Calendar's monthly
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
            <div className="min-w-0 flex-1 md:flex md:items-start md:gap-4">
                <div className="min-w-0 md:flex-1">
                    <div className="flex justify-end">
                        <NarrationFlag analysis={analysis} />
                    </div>
                    <AnalysisStatus
                        analysis={analysis}
                        inertiaReloadProps={inertiaReloadProps}
                        awaitingSchedule={awaitingSchedule}
                        awaitingScheduleLabel={awaitingScheduleLabel}
                        chained
                        isChainHead={isChainHead}
                        size="sm"
                        renderContent={(content) => (
                            <p className="narration-dense m-0">
                                {renderBold(content)}
                            </p>
                        )}
                    />
                    {fallback && analysis.status !== 'done' && (
                        <p className="narration-dense m-0">
                            {awaitingSchedule && (
                                <span className="font-semibold text-text-3">
                                    For now:{' '}
                                </span>
                            )}
                            {fallback}
                        </p>
                    )}
                </div>
                {(chips || (notification && analysis.status === 'done')) && (
                    <div
                        data-testid="recap-secondary"
                        className="mt-1.5 flex items-center justify-between gap-2 md:mt-0 md:w-2/5 md:shrink-0 md:flex-col md:items-end md:gap-1.5"
                    >
                        <div className="flex flex-wrap gap-1.5 md:justify-end">
                            {chips}
                        </div>
                        {notification && analysis.status === 'done' && (
                            <SendNotificationButton
                                url={notification.url}
                                retryAfterSeconds={
                                    notification.retryAfterSeconds
                                }
                                reachable={notificationsReachable}
                            />
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
