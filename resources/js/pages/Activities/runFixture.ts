import type { ActivityDetail } from '@/types/inertia';

import type { RunWithDetail } from './weekBuckets';

export function run(
    id: number,
    name: string,
    isoDate: string | null = '2026-05-19T06:00:00',
): RunWithDetail {
    return {
        id,
        user_id: 1,
        analyzed_at: '2026-05-19',
        detail: {
            id,
            activity_id: id,
            name,
            start_date_local: isoDate,
            distance: 5000,
            elapsed_time: 1800,
            trimp_edwards: 50,
            average_heartrate: 145,
        } as ActivityDetail,
    };
}
