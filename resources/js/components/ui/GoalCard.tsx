import Card from '@/components/ui/Card';
import ProgressBar from '@/components/ui/ProgressBar';
import { useCountUp } from '@/hooks/useCountUp';
import { cn } from '@/lib/cn';
import { formatGoalNumber, goalProgressRatio } from '@/lib/goalProgress';

export interface Goal {
    id: number;
    title: string;
    current: number;
    target: number;
    unit: string;
    is_completed: boolean;
}

/**
 * Progress tile for a single goal: title, animated `current/target` figure with
 * its unit, and a fill bar. `h-full` keeps a row of them equal-height when the
 * titles wrap to different line counts.
 */
export default function GoalCard({ goal }: Readonly<{ goal: Goal }>) {
    const current = useCountUp(goal.current);

    return (
        <Card
            padding="sm"
            className={cn(
                'flex h-full flex-col gap-2',
                goal.is_completed && 'border-horizon/30 bg-horizon/[0.06]',
            )}
        >
            <p className="text-sm font-semibold text-ink">{goal.title}</p>
            <div className="mt-auto">
                <div className="mb-1 flex items-baseline justify-between font-mono text-[11px] tabular-nums text-ink-3">
                    <span>
                        {formatGoalNumber(current)}
                        <span className="text-ink-3">/</span>
                        {formatGoalNumber(goal.target)}
                    </span>
                    <span>{goal.unit}</span>
                </div>
                <ProgressBar
                    value={goalProgressRatio(goal.current, goal.target)}
                    tone={goal.is_completed ? 'horizon' : 'sky'}
                    ariaLabel={`${goal.title}: ${formatGoalNumber(goal.current)}/${formatGoalNumber(goal.target)} ${goal.unit}`}
                />
            </div>
        </Card>
    );
}
