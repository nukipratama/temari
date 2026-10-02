import { formatRelativeId } from '@/lib/pace';

interface LastOpenProps {
    lastSeenAt: string | null;
    away: boolean;
    isDemo: boolean;
}

/**
 * When the athlete last opened the app, and whether that leaves them outside
 * the window scheduled narration spends on. `away` comes from the server's
 * own `RecentlyActiveUsers::includes()`, so this never re-derives the rule.
 */
export default function LastOpen({
    lastSeenAt,
    away,
    isDemo,
}: Readonly<LastOpenProps>) {
    if (isDemo) {
        return <p className="text-xs text-text-3">demo (never stamped)</p>;
    }

    return (
        <div className="text-xs text-text-3">
            <p>
                {lastSeenAt === null
                    ? 'never opened'
                    : `opened ${formatRelativeId(lastSeenAt)}`}
            </p>
            {away && (
                <>
                    <span className="mt-1 inline-block rounded-full bg-horizon/15 px-1.5 py-0.5 text-[0.625rem] font-normal text-horizon-ink">
                        away: scheduled narration paused
                    </span>
                    <p className="mt-1">
                        Their next open re-includes them and triggers the
                        catch-up narration.
                    </p>
                </>
            )}
        </div>
    );
}
