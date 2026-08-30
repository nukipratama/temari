import { describe, expect, it } from 'vitest';

import { run } from './runFixture';
import { groupByWeek, type RunWithDetail } from './weekBuckets';

describe('groupByWeek', () => {
    it('buckets runs by ISO week, Monday-start', () => {
        const buckets = groupByWeek([
            run(101, 'Tuesday', '2026-05-19T06:00:00'),
            run(102, 'Sunday', '2026-05-24T06:00:00'),
            run(103, 'Last week', '2026-05-12T06:00:00'),
        ]);

        expect(buckets.map((b) => b.weekStart)).toEqual([
            '2026-05-18',
            '2026-05-11',
        ]);
        expect(buckets[0].weekEnding).toBe('2026-05-24');
        expect(buckets[0].runs.length).toBe(2);
        expect(buckets[0].totalKm).toBeCloseTo(10);
        expect(buckets[0].totalTrimp).toBe(100);
    });

    it('collects dateless runs into one bucket at the end', () => {
        const buckets = groupByWeek([
            run(101, 'Tuesday', '2026-05-19T06:00:00'),
            run(999, 'No date', null),
        ]);

        expect(buckets[buckets.length - 1]).toMatchObject({
            weekStart: 'orphans',
            weekEnding: 'orphans',
            label: 'No date',
            totalKm: 5,
            totalTrimp: 50,
        });
    });

    it('skips a row with no detail rather than throwing', () => {
        const headless = {
            id: 1,
            user_id: 1,
            analyzed_at: null,
            detail: null,
        } as unknown as RunWithDetail;

        expect(groupByWeek([headless])).toEqual([]);
    });

    it('leaves TRIMP unknown when a week ran but nothing scored', () => {
        const bare = run(101, 'No metrics', '2026-05-19T06:00:00');
        bare.detail.distance = null;
        bare.detail.trimp_edwards = null;

        const [bucket] = groupByWeek([bare]);
        expect(bucket.totalKm).toBe(0);
        expect(bucket.totalTrimp).toBeNull();
    });

    it('keeps a summary-only week apart from the scored week beside it', () => {
        const scored = run(101, 'With HR', '2026-05-19T06:00:00');
        const unscored = run(102, 'No HR', '2026-05-12T06:00:00');
        unscored.detail.trimp_edwards = null;

        const [scoredWeek, unscoredWeek] = groupByWeek([scored, unscored]);

        expect(scoredWeek.totalTrimp).toBe(50);
        expect(unscoredWeek.totalTrimp).toBeNull();
        expect(unscoredWeek.runs.length).toBe(1);
    });
});
