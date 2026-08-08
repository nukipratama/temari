import { useMemo } from 'react';

import type { TemariPose } from '@/components/temari/TemariProto';
import type { ShareKartuData } from '@/lib/shareCard';
import type {
    ActivityDetail,
    AnalysisPayload,
    CardEdition,
    Mood,
    RunCard,
    StoryLine,
    StreamSummary,
} from '@/types/inertia';

import {
    formatKm,
    formatNaiveTimeId,
    formatPace,
    formatShortDateId,
    paceSecPerKm,
} from '@/lib/pace';
import {
    RARITY_LABELS,
    avgCadenceFromDetail,
    badgeEmblem,
    badgeName,
    fastestKmFromDetail,
    kartuPropsFromDetail,
} from '@/lib/runcard';
import { MOOD_TO_POSE } from '@/lib/temariPose';
import { districtFromLocation } from '@/pages/HariIni/helpers';

/** The run's RunCard, enriched with the flavor/edition/share fields this page's
 *  card section needs (see RunController::cardPayload). */
export type RunCardDetail = Omit<RunCard, 'activity' | 'edition'> & {
    edition: CardEdition | null;
    flavor_analysis: AnalysisPayload;
    public_share_url: string;
};

/** Effort of this run vs the runner's own 28-day TRIMP baseline (see RelativeEffort). */
export interface RelativeEffortPayload {
    trimp: number;
    baseline: number | null;
    ratio: number | null;
    band: 'well_above' | 'above' | 'typical' | 'below' | null;
}

/** Human "vs biasanya" line per band. Null band (thin baseline) shows nothing. */
const EFFORT_SUB: Record<NonNullable<RelativeEffortPayload['band']>, string> = {
    well_above: 'lebih berat dari biasanya',
    above: 'agak lebih berat dari biasanya',
    typical: 'kayak biasanya',
    below: 'lebih enteng dari biasanya',
};

interface UseRunShowArgs {
    detail: ActivityDetail;
    card: RunCardDetail | null;
    storyLine: StoryLine | null;
    moodFallback: Mood;
    relativeEffort: RelativeEffortPayload | null;
}

export function useRunShow({
    detail,
    card,
    storyLine,
    moodFallback,
    relativeEffort,
}: UseRunShowArgs) {
    const summary: StreamSummary = detail.stream_summary ?? {};
    const perKm = summary.per_km ?? [];
    const partialSplit = summary.partial_split ?? null;

    const mood: Mood = storyLine?.mood ?? moodFallback;
    const pose: TemariPose = MOOD_TO_POSE[mood];

    const km = formatKm(detail.distance);
    const paceSec = paceSecPerKm(detail.elapsed_time, detail.distance);
    const pace = paceSec != null ? formatPace(paceSec) : '—';
    const hr =
        detail.average_heartrate != null
            ? Math.round(detail.average_heartrate)
            : null;
    const trimp =
        detail.trimp_edwards != null ? Math.round(detail.trimp_edwards) : null;
    const effortSub =
        relativeEffort?.band != null
            ? EFFORT_SUB[relativeEffort.band]
            : undefined;

    const kartuProps = useMemo(() => kartuPropsFromDetail(detail), [detail]);
    const cardBadges = useMemo(() => (card?.badges ?? []).slice(0, 3), [card]);
    const cadence = avgCadenceFromDetail(detail);
    const fastestKm = fastestKmFromDetail(detail);
    const rarityLabel = card ? RARITY_LABELS[card.rarity] : null;

    const shareDate = detail.start_date_local
        ? (() => {
              const time = formatNaiveTimeId(detail.start_date_local);
              const shortDate = formatShortDateId(detail.start_date_local);
              return time === null ? shortDate : `${shortDate}\n${time}`;
          })()
        : null;

    const shareWeather = (() => {
        if (detail.weather_temp_c == null) {
            return null;
        }
        const temp = `${Math.round(detail.weather_temp_c)}°C`;
        const wind =
            detail.weather_wind_speed_kmh != null
                ? `, angin ${Math.round(detail.weather_wind_speed_kmh)} km/j`
                : '';
        return `${temp}${wind}`;
    })();

    const shareData: ShareKartuData | null = useMemo(
        () =>
            card === null
                ? null
                : {
                      id: card.id,
                      name: card.special_move,
                      shareUrl: card.public_share_url,
                      rarity: card.rarity,
                      mood,
                      subtitle: kartuProps.subtitle,
                      date: shareDate,
                      km,
                      durasi: kartuProps.durasi,
                      pace: paceSec != null ? formatPace(paceSec) : null,
                      trimp: kartuProps.trimp,
                      hr: hr != null ? `${hr} bpm` : null,
                      cadence: cadence != null ? `${cadence} spm` : null,
                      fastestKm: fastestKm != null ? `${fastestKm}/km` : null,
                      ascent:
                          detail.total_elevation_gain != null
                              ? `${Math.round(detail.total_elevation_gain)} m`
                              : null,
                      zonePct: kartuProps.zonePct,
                      location: districtFromLocation(
                          detail.location_name ?? null,
                      ),
                      weather: shareWeather,
                      wind:
                          detail.weather_wind_speed_kmh != null
                              ? `${Math.round(detail.weather_wind_speed_kmh)} km/j`
                              : null,
                      tags: cardBadges.map((b) => badgeName(b)),
                      tagEmojis: cardBadges.map((b) => badgeEmblem(b)),
                      quote: card.flavor_analysis.content ?? null,
                      polyline: detail.summary_polyline ?? null,
                      distanceKm:
                          detail.distance != null
                              ? detail.distance / 1000
                              : null,
                      edition: card.edition ?? null,
                  },
        [
            card,
            mood,
            kartuProps,
            shareDate,
            km,
            paceSec,
            hr,
            cadence,
            fastestKm,
            detail,
            shareWeather,
            cardBadges,
        ],
    );

    return {
        summary,
        perKm,
        partialSplit,
        mood,
        pose,
        km,
        pace,
        hr,
        trimp,
        effortSub,
        kartuProps,
        cardBadges,
        cadence,
        fastestKm,
        rarityLabel,
        shareData,
    };
}
