import { usePage } from '@inertiajs/react';

import type { SharedProps } from '@/types/inertia';

import Chip from '@/components/ui/Chip';
import { formatRelativeId } from '@/lib/pace';

interface LastOpenProps {
    /** The app-timezone ISO stamp of the athlete's first request on their last active day. */
    lastSeenAt: string | null;
    away: boolean;
    isDemo: boolean;
}

function activityLabel(lastSeenAt: string, today: string): string {
    return lastSeenAt.slice(0, 10) === today
        ? `active today · first open ${lastSeenAt.slice(11, 16)}`
        : `last active ${formatRelativeId(lastSeenAt)}`;
}

/**
 * The athlete's last active day, and whether that leaves them outside the
 * window scheduled narration spends on. `away` comes from the server's own
 * `RecentlyActiveUsers::includes()`, so this never re-derives the rule.
 */
export default function LastOpen({
    lastSeenAt,
    away,
    isDemo,
}: Readonly<LastOpenProps>) {
    const { today } = usePage<SharedProps>().props;

    if (isDemo) {
        return <p className="text-xs text-text-3">demo (never stamped)</p>;
    }

    return (
        <div className="text-xs text-text-3">
            <p>
                {lastSeenAt === null
                    ? 'never opened'
                    : activityLabel(lastSeenAt, today)}
            </p>
            {away && (
                <>
                    <Chip tone="horizon" size="md" className="mt-1">
                        away: scheduled narration paused
                    </Chip>
                    <p className="mt-1">
                        Their next open re-includes them and triggers the
                        catch-up narration.
                    </p>
                </>
            )}
        </div>
    );
}
