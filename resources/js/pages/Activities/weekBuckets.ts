import type { Activity, ActivityDetail } from '@/types/inertia';

import { formatIdDate, isoDateLocal, mondayOf, sundayOf } from '@/lib/pace';

export type RunWithDetail = Activity & { detail: ActivityDetail };

export interface WeekBucket {
    weekStart: string;
    /** ISO date string for the Sunday of this week — matches WeeklySnapshot.week_ending. */
    weekEnding: string;
    label: string;
    runs: RunWithDetail[];
    totalKm: number;
    /** Null when the week's runs carried no heart rate: unknown, not zero. */
    totalTrimp: number | null;
}

function weekRangeLabel(monday: Date): string {
    const sunday = sundayOf(monday);
    const start = formatIdDate(monday.toISOString(), 'long');
    const end = formatIdDate(sunday.toISOString(), 'long');
    return `${start} - ${end}`;
}

/**
 * Bucket activities by ISO week (Monday-start). Activities without a
 * start_date_local fall into a single "No date" bucket at the end.
 */
export function groupByWeek(rows: ReadonlyArray<RunWithDetail>): WeekBucket[] {
    const byKey = new Map<string, WeekBucket>();
    const ordered: string[] = [];
    const orphans: RunWithDetail[] = [];

    for (const row of rows) {
        if (!row.detail) continue;
        const iso = row.detail.start_date_local;
        if (iso === null) {
            orphans.push(row);
            continue;
        }
        const monday = mondayOf(iso);
        const key = isoDateLocal(monday);
        let bucket = byKey.get(key);
        if (!bucket) {
            bucket = {
                weekStart: key,
                weekEnding: isoDateLocal(sundayOf(monday)),
                label: weekRangeLabel(monday),
                runs: [],
                totalKm: 0,
                totalTrimp: null,
            };
            byKey.set(key, bucket);
            ordered.push(key);
        }
        bucket.runs.push(row);
        if (row.detail.distance !== null)
            bucket.totalKm += row.detail.distance / 1000;
        if (row.detail.trimp_edwards !== null)
            bucket.totalTrimp =
                (bucket.totalTrimp ?? 0) + row.detail.trimp_edwards;
    }

    const buckets = ordered.map((k) => byKey.get(k)!);

    if (orphans.length > 0) {
        buckets.push({
            weekStart: 'orphans',
            weekEnding: 'orphans',
            label: 'No date',
            runs: orphans,
            totalKm: orphans.reduce(
                (acc, r) => acc + (r.detail.distance ?? 0) / 1000,
                0,
            ),
            totalTrimp: orphans.reduce<number | null>(
                (acc, r) =>
                    r.detail.trimp_edwards === null
                        ? acc
                        : (acc ?? 0) + r.detail.trimp_edwards,
                null,
            ),
        });
    }

    return buckets;
}
