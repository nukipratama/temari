import { useMemo } from 'react';

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
import { districtFromLocation } from '@/pages/Home/helpers';

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
                ? `, wind ${Math.round(detail.weather_wind_speed_kmh)} km/h`
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
                      duration: kartuProps.duration,
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
                              ? `${Math.round(detail.weather_wind_speed_kmh)} km/h`
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
        laps,
        partialSplit,
        mood,
        km,
        pace,
        paceSec,
        hr,
        trimp,
        kartuProps,
        cardBadges,
        cadence,
        fastestKm,
        rarityLabel,
        shareData,
    };
}
