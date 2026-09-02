export type InboxBucket = 'today' | 'week' | 'earlier';

export const BUCKET_LABEL: Record<InboxBucket, string> = {
    today: 'Today',
    week: 'This Week',
    earlier: 'Earlier',
};

const BUCKET_ORDER: readonly InboxBucket[] = ['today', 'week', 'earlier'];

function startOfLocalDay(d: Date): Date {
    const x = new Date(d);
    x.setHours(0, 0, 0, 0);
    return x;
}

// Monday-start, matching the backend's own week convention (Carbon::MONDAY,
// e.g. app/Services/Run/Plan/Periodizer.php:56).
function startOfLocalWeek(d: Date): Date {
    const x = startOfLocalDay(d);
    const offset = (x.getDay() + 6) % 7;
    x.setDate(x.getDate() - offset);
    return x;
}

/**
 * `created_at` is a true instant (ISO-8601 with offset), so this reads it via
 * `new Date()` directly rather than pace.ts's `mondayOf`, which is built for
 * Strava's naive `start_date_local` values.
 */
export function bucketOf(
    createdAt: string | null,
    now: Date = new Date(),
): InboxBucket {
    if (!createdAt) return 'earlier';
    const created = new Date(createdAt);
    if (Number.isNaN(created.getTime())) return 'earlier';

    if (created >= startOfLocalDay(now)) return 'today';
    if (created >= startOfLocalWeek(now)) return 'week';
    return 'earlier';
}

export interface InboxBucketGroup<T> {
    bucket: InboxBucket;
    items: T[];
}

/**
 * Groups items into today / this week / earlier, in that display order,
 * omitting empty buckets. Pure client-side grouping over whatever page of
 * rows is already loaded — no backend shape change.
 */
export function groupByBucket<T extends { created_at: string | null }>(
    items: readonly T[],
    now: Date = new Date(),
): InboxBucketGroup<T>[] {
    const buckets = new Map<InboxBucket, T[]>();
    for (const item of items) {
        const bucket = bucketOf(item.created_at, now);
        const group = buckets.get(bucket);
        if (group) {
            group.push(item);
        } else {
            buckets.set(bucket, [item]);
        }
    }
    return BUCKET_ORDER.filter((bucket) => buckets.has(bucket)).map(
        (bucket) => ({
            bucket,
            // Non-null: filtered to buckets present in the map above.
            items: buckets.get(bucket) as T[],
        }),
    );
}
