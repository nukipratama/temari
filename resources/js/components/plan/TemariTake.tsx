import type { AnalysisPayload } from '@/types/inertia';

import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';

/**
 * Temari's read on a season, a week or a single day — the labelled
 * narration block the plan repeats at all three levels of the timeline.
 */
export default function TemariTake({
    analysis,
    allowReanalyze = true,
    className,
}: Readonly<{
    analysis: AnalysisPayload;
    allowReanalyze?: boolean;
    className?: string;
}>) {
    return (
        <div className={cn(className)}>
            <div className="flex items-center gap-1.5 text-horizon-ink">
                <Icon
                    icon="mdi:auto-awesome"
                    className="size-3.5"
                    aria-hidden
                />
                <span className="text-label-micro">Temari&apos;s take</span>
            </div>
            <div className="mt-1">
                <AnalysisStatus
                    analysis={analysis}
                    inertiaReloadProps={['planNarration']}
                    size="sm"
                    showTimestamp={false}
                    allowReanalyze={allowReanalyze}
                    renderContent={(content) => (
                        <p className="narration">{content}</p>
                    )}
                />
            </div>
        </div>
    );
}
