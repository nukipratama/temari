import type { Activity } from '@/types/inertia';

/** Activity/run detail page (`/aktivitas/{id}`). Pass an Activity, or any row carrying `activity_id`. */
export function aktivitasUrl(source: { activity_id: number } | Pick<Activity, 'id'>): string {
    const id = 'activity_id' in source ? source.activity_id : source.id;
    return `/aktivitas/${id}`;
}
