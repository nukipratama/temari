import type { Activity, RunCard } from '@/types/inertia';

/**
 * Typed link builders for the two detail pages. They take the domain object,
 * not a bare id, so the type system pulls the right field: a `RunCard` carries
 * both `id` (the card) and `activity_id` (its run), and passing the same card
 * to either helper yields the two different, correct URLs. This is the guard
 * against the card-id / activity-id mixup (both are sequential bigints, so a
 * swapped id silently resolves to a different real entity instead of 404ing).
 */

/** Card detail page (`/kartu/{id}`). Pass the RunCard, or anything with its own `id`. */
export function kartuUrl(card: Pick<RunCard, 'id'>): string {
    return `/kartu/${card.id}`;
}

/** Activity/run detail page (`/aktivitas/{id}`). Pass an Activity, or any row carrying `activity_id`. */
export function aktivitasUrl(source: { activity_id: number } | Pick<Activity, 'id'>): string {
    const id = 'activity_id' in source ? source.activity_id : source.id;
    return `/aktivitas/${id}`;
}
