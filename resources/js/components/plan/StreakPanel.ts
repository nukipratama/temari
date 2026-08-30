/**
 * Type-only: the weekly-streak panel UI moved to Trends' badge board (S6/S4
 * decision). Profile's SeasonStreakPanel still renders this same payload
 * shape, so the type stays here rather than moving.
 */
export interface StreakSummary {
    weeks: number;
    rest_weeks_held: number;
    rest_weeks_cap: number;
    /** Null once the held rest weeks are at their cap, since no more can arrive. */
    weeks_to_next_rest_week: number | null;
    ran_this_week: boolean;
    week_ends_on: string;
    last_forgiven_week: string | null;
}
