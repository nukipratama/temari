import type { Activity, AnalysisPayload } from '@/types/inertia';

/** Activity/run detail page (`/aktivitas/{id}`). Pass an Activity, or any row carrying `activity_id`. */
export function aktivitasUrl(
    source: { activity_id: number } | Pick<Activity, 'id'>,
): string {
    const id = 'activity_id' in source ? source.activity_id : source.id;
    return `/aktivitas/${id}`;
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
