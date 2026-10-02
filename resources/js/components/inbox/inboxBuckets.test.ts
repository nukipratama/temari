import { afterEach, describe, expect, it, vi } from 'vitest';

import { bucketOf, groupByBucket } from './inboxBuckets';

const today = '2026-08-19';
const earlierToday = '2026-08-19T07:30:00+07:00';
const monday = '2026-08-17T08:00:00+07:00';
const beforeMonday = '2026-08-16T08:00:00+07:00';

describe('bucketOf', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('buckets a null/invalid created_at as earlier', () => {
        expect(bucketOf(null, today)).toBe('earlier');
        expect(bucketOf('not-a-date', today)).toBe('earlier');
    });

    it('buckets the same server day as today', () => {
        expect(bucketOf(earlierToday, today)).toBe('today');
    });

    it('buckets an earlier day this week (Monday-start) as week', () => {
        expect(bucketOf(monday, today)).toBe('week');
    });

    it("buckets a day before this week's Monday as earlier", () => {
        expect(bucketOf(beforeMonday, today)).toBe('earlier');
    });

    it('files a row stamped today in Jakarta under today while the device clock is already tomorrow', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 7, 20, 1, 30));

        expect(bucketOf('2026-08-19T23:10:00+07:00', today)).toBe('today');
    });

    it('keeps a row stamped yesterday in Jakarta out of today while the device clock is still yesterday', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 7, 18, 22, 0));

        expect(bucketOf('2026-08-18T21:00:00+07:00', today)).toBe('week');
    });
});

describe('groupByBucket', () => {
    it('groups items into today / week / earlier in that order, omitting empty buckets', () => {
        const items = [
            { id: 1, created_at: earlierToday },
            { id: 2, created_at: monday },
            { id: 3, created_at: beforeMonday },
        ];

        expect(groupByBucket(items, today)).toEqual([
            { bucket: 'today', items: [items[0]] },
            { bucket: 'week', items: [items[1]] },
            { bucket: 'earlier', items: [items[2]] },
        ]);
    });

    it('omits a bucket with no items', () => {
        const items = [{ id: 1, created_at: earlierToday }];

        expect(groupByBucket(items, today)).toEqual([
            { bucket: 'today', items },
        ]);
    });

    it('returns an empty array for no items', () => {
        expect(groupByBucket([], today)).toEqual([]);
    });
});
