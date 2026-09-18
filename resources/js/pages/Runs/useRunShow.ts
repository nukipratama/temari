import { useMemo } from 'react';

import type { ShareCardTarget } from '@/components/card/ShareCardModal';
import type {
    ActivityDetail,
    AnalysisPayload,
    CardEdition,
    Mood,
    RunCard,
    StoryLine,
    StreamSummary,
} from '@/types/inertia';

import { formatKm, formatPace, paceSecPerKm } from '@/lib/pace';
import {
    avgCadenceFromDetail,
    fastestKmFromDetail,
    cardPropsFromDetail,
} from '@/lib/runcard';

/** The run's RunCard, enriched with the flavor/edition/share fields this page's
 *  card section needs (see RunController::cardPayload). */
export type RunCardDetail = Omit<RunCard, 'activity' | 'edition'> & {
    edition: CardEdition | null;
    flavor_analysis: AnalysisPayload;
    public_share_url: string;
};

interface UseRunShowArgs {
    detail: ActivityDetail;
    card: RunCardDetail | null;
    storyLine: StoryLine | null;
    moodFallback: Mood;
}

export function useRunShow({
    detail,
    card,
    storyLine,
    moodFallback,
}: UseRunShowArgs) {
    const summary: StreamSummary = detail.stream_summary ?? {};
    const perKm = summary.per_km ?? [];
    const laps = summary.laps ?? [];
    const partialSplit = summary.partial_split ?? null;

    const mood: Mood = storyLine?.mood ?? moodFallback;

    const km = formatKm(detail.distance);
    const paceSec = paceSecPerKm(detail.elapsed_time, detail.distance);
    const pace = paceSec != null ? formatPace(paceSec) : '—';
    const hr =
        detail.average_heartrate != null
            ? Math.round(detail.average_heartrate)
            : null;
    const trimp =
        detail.trimp_edwards != null ? Math.round(detail.trimp_edwards) : null;
    const cardProps = useMemo(() => cardPropsFromDetail(detail), [detail]);
    const cardBadges = useMemo(() => (card?.badges ?? []).slice(0, 3), [card]);
    const cadence = avgCadenceFromDetail(detail);
    const fastestKm = fastestKmFromDetail(detail);

    const shareData: ShareCardTarget | null = useMemo(
        () =>
            card === null
                ? null
                : {
                      activityId: detail.activity_id,
                      name: card.special_move,
                      shareUrl: card.public_share_url,
                      quote: card.flavor_analysis.content ?? null,
                  },
        [card, detail.activity_id],
    );

    return {
        summary,
        perKm,
        laps,
        partialSplit,
        mood,
        km,
        pace,
        paceSec,
        hr,
        trimp,
        cardProps,
        cardBadges,
        cadence,
        fastestKm,
        shareData,
    };
}
