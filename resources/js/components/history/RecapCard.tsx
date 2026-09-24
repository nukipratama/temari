import type { ReactNode } from 'react';

import type { AnalysisPayload, Mood } from '@/types/inertia';

import SendNotificationButton from '@/components/SendNotificationButton';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import MascotWatermark from '@/components/temari/MascotWatermark';
import { writingPose } from '@/components/temari/TemariMascot';
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
    /** Metric chips: one wrapping row above the narration. */
    chips?: ReactNode;
    /** The "send this recap" affordance, only offered once narration is done. */
    notification?: { url: string; retryAfterSeconds: number | null } | null;
    size?: 'week' | 'month';
    className?: string;
}

const DEFAULT_RELOAD_PROPS = ['weeklySnapshots', 'historicalSnapshots'];

/**
 * Temari's narrative recap for a week or a month: Temari posed to the period's
 * mood, as a watermark behind a single column holding the period's chips, then the narration, then the
 * "send it" affordance once the block is done. Shared shape for the Feed's
 * weekly recap and the Calendar's monthly recap so both read as the same
 * feature at two grains.
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
    const pose = writingPose(mood ?? 'neutral', analysis);
    const notificationsReachable = useNotificationsReachable();

    return (
        <div
            className={cn(
                'relative isolate overflow-hidden rounded-md border border-border-strong bg-card shadow-e1',
                size === 'week' ? 'p-3' : 'p-3.5',
                className,
            )}
        >
            <MascotWatermark pose={pose} className="-right-14 -bottom-15" />
            <div className="min-w-0">
                {(chips || (notification && analysis.status === 'done')) && (
                    <div
                        data-testid="recap-secondary"
                        className="mb-1.5 flex flex-wrap items-center gap-1.5"
                    >
                        {chips}
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
        </div>
    );
}
