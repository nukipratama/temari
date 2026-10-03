import { isoDateLocal, mondayOf, parseNaiveLocalDate } from '@/lib/pace';

export type InboxBucket = 'today' | 'week' | 'earlier';

export const BUCKET_LABEL: Record<InboxBucket, string> = {
    today: 'Today',
    week: 'This Week',
    earlier: 'Earlier',
};

const BUCKET_ORDER: readonly InboxBucket[] = ['today', 'week', 'earlier'];

/**
 * `created_at` arrives stamped in the server's zone, so its leading date is
 * the server's calendar day; weeks are Monday-start like the backend's.
 */
function bucketOf(createdAt: string | null, today: string): InboxBucket {
    const created = createdAt ? parseNaiveLocalDate(createdAt) : null;
    if (created === null) return 'earlier';

    const createdDay = isoDateLocal(created);
    if (createdDay >= today) return 'today';
    if (createdDay >= isoDateLocal(mondayOf(today))) return 'week';
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
    today: string,
): InboxBucketGroup<T>[] {
    const buckets = new Map<InboxBucket, T[]>();
    for (const item of items) {
        const bucket = bucketOf(item.created_at, today);
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
