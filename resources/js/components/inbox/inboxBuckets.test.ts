import { describe, expect, it } from 'vitest';

import { bucketOf, groupByBucket } from './inboxBuckets';

// Local-time anchors, matching bucketOf's own local Date arithmetic — so the
// test is deterministic regardless of the runner's timezone. Wednesday, so
// "this week" (Monday-start) safely spans several prior local days.
const now = new Date(2026, 7, 19, 12, 0, 0); // Wed 19 Aug 2026, local noon
const today = (hoursAgo: number) =>
    new Date(now.getTime() - hoursAgo * 60 * 60 * 1000).toISOString();
const monday = new Date(2026, 7, 17, 8, 0, 0).toISOString(); // this week
const beforeMonday = new Date(2026, 7, 16, 8, 0, 0).toISOString(); // last week

describe('bucketOf', () => {
    it('buckets a null/invalid created_at as earlier', () => {
        expect(bucketOf(null, now)).toBe('earlier');
        expect(bucketOf('not-a-date', now)).toBe('earlier');
    });

    it('buckets the same local day as today', () => {
        expect(bucketOf(today(2), now)).toBe('today');
    });

    it('buckets an earlier day this week (Monday-start) as week', () => {
        expect(bucketOf(monday, now)).toBe('week');
    });

    it("buckets a day before this week's Monday as earlier", () => {
        expect(bucketOf(beforeMonday, now)).toBe('earlier');
    });
});

describe('groupByBucket', () => {
    it('groups items into today / week / earlier in that order, omitting empty buckets', () => {
        const items = [
            { id: 1, created_at: today(2) },
            { id: 2, created_at: monday },
            { id: 3, created_at: beforeMonday },
        ];

        expect(groupByBucket(items, now)).toEqual([
            { bucket: 'today', items: [items[0]] },
            { bucket: 'week', items: [items[1]] },
            { bucket: 'earlier', items: [items[2]] },
        ]);
    });

    it('omits a bucket with no items', () => {
        const items = [{ id: 1, created_at: today(2) }];

        expect(groupByBucket(items, now)).toEqual([{ bucket: 'today', items }]);
    });

    it('returns an empty array for no items', () => {
        expect(groupByBucket([], now)).toEqual([]);
    });
});
