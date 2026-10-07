import { Sparkles } from 'lucide-react';

import type { AnalysisPayload } from '@/types/inertia';

import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';

/**
 * Temari's take on the season — the labelled narration block in the plan's
 * season header.
 */
export default function TemariTake({
    analysis,
    className,
}: Readonly<{
    analysis: AnalysisPayload;
    className?: string;
}>) {
    return (
        <div className={cn(className)}>
            <div className="flex items-center gap-1.5 text-horizon-ink">
                <Icon icon={Sparkles} className="size-3.5" aria-hidden />
                <span className="text-label-micro">Temari's take</span>
            </div>
            <div className="mt-1">
                <AnalysisStatus
                    analysis={analysis}
                    thinkingMark
                    inertiaReloadProps={['planNarration']}
                    size="sm"
                    renderContent={(content) => (
                        <p className="narration">{content}</p>
                    )}
                />
            </div>
        </div>
    );
}
