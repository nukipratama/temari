import type { Activity, AnalysisPayload } from '@/types/inertia';

/** Activity/run detail page (`/activities/{id}`). Pass an Activity, or any row carrying `activity_id`. */
export function activityUrl(
    source: { activity_id: number } | Pick<Activity, 'id'>,
): string {
    const id = 'activity_id' in source ? source.activity_id : source.id;
    return `/activities/${id}`;
}

/**
 * Share-card PNG endpoint (`/activities/{id}/card.png`). `style` and `aspect`
 * name the print, and every other entry is an optional-fact toggle the server
 * reads as a boolean. The tuple is the server's cache key, so the same
 * arguments always return the same bytes.
 */
export function runCardImageUrl(
    activityId: number,
    params: Record<string, string | boolean>,
): string {
    const query = new URLSearchParams(
        Object.entries(params).map(([key, value]) => [key, String(value)]),
    );

    return `/activities/${activityId}/card.png?${query.toString()}`;
}

/**
 * Analysis trigger endpoint (`/api/analyses/{type}/{subjectId}/trigger`). The
 * id in the path is the analysed subject's id (`subject_id`), never the
 * analysis row's own `id`.
 */
export function analysisTriggerUrl(
    analysis: Pick<AnalysisPayload, 'type' | 'subject_id' | 'discriminator'>,
): string {
    const base = `/api/analyses/${analysis.type}/${analysis.subject_id}/trigger`;

    return analysis.discriminator
        ? `${base}?discriminator=${encodeURIComponent(analysis.discriminator)}`
        : base;
}
