// Generated from the PHP enums — see generated.ts (`php artisan typescript:enums`).
// Imported for local use below and re-exported so consumers keep importing from here.
import type {
    AnalysisStatus,
    AnalysisType,
    NotificationKind,
    Rarity,
} from './generated';

export type {
    AnalysisStatus,
    AnalysisType,
    NotificationKind,
    Rarity,
} from './generated';

export type Mood =
    'blazing' | 'easy' | 'wobbly' | 'gassed' | 'overloaded' | 'chill';

export type Tone = 'neutral' | 'positive' | 'warning' | 'alert';

export type RecoveryTone = 'positive' | 'warning' | 'alert' | 'neutral';

export interface AuthUser {
    id: number;
    name: string;
    first_name: string;
    avatar_url: string | null;
    /** Demo account: Telegram writes are guarded (show the demo-limit modal). */
    is_demo: boolean;
}

export interface PendingReveal {
    card_id: number;
    activity_id: number;
    rarity: Rarity;
    special_move: string;
    mood: Mood;
    badges: string[] | null;
    detail_name: string | null;
    distance_m: number | null;
    elapsed_time_sec: number | null;
    trimp_edwards: number | null;
    average_heartrate?: number | null;
    stream_summary?: StreamSummary | null;
    summary_polyline?: string | null;
    public_share_url: string;
    edition: CardEdition;
}

export type StravaSyncState = 'disconnected' | 'revoked' | 'syncing' | 'ready';

export interface StravaSync {
    /**
     * Honest connection/ingest state the UI branches on. `syncing` means
     * connected but no analyzed run has landed yet (backfill in flight); a
     * "connected" boolean is derived from this, not shipped separately.
     */
    state: StravaSyncState;
    last_synced_at: string | null;
}

/** Resolved server-side from the user's equipped UserUnlock rows. */
export type EquippedSlot =
    'medal' | 'headband' | 'shirt' | 'shorts' | 'shoes' | 'aura';

export interface EquippedAccessories {
    medal: string | null;
    headband: string | null;
    shirt: string | null;
    shorts: string | null;
    shoes: string | null;
    aura: string | null;
}

/** Flashed by GrantEligibleUnlocksAction when a user earns their first new accessory in a request. */
export interface UnlockFlash {
    unlock_key: string;
    name: string;
    icon: string;
    is_major: boolean;
}

/**
 * One row of the notification centre, flattened by `InboxController` so the
 * page never reads the raw `payload` blob. `run_card_id` and `unlock` are the
 * replay handles: either one re-runs the celebration this row is a record of,
 * rather than describing it.
 */
export interface InboxItem {
    id: number;
    kind: NotificationKind;
    title: string;
    body: string | null;
    /** True instant (ISO-8601 with offset), not a naive wall-clock date. */
    created_at: string | null;
    read_at: string | null;
    url: string | null;
    run_card_id: number | null;
    rarity: Rarity | null;
    unlock: UnlockFlash | null;
}

/**
 * The race the user is currently training for, shared app-wide. "Race" is the
 * user-facing name; live accessory-unlock progress lives on `/accessories`,
 * see AccessoriesItem.
 */
export interface ActiveRace {
    id: number;
    race_date: string;
    distance_m: number;
    goal_time_sec: number;
    name: string | null;
}

export interface SharedProps {
    auth: { user: AuthUser | null };
    flash: {
        success: string | null;
        error: string | null;
        info: string | null;
        unlock?: UnlockFlash | null;
    };
    demoLoginEnabled: boolean;
    pendingReveal?: PendingReveal | null;
    equippedAccessories?: EquippedAccessories | null;
    stravaSync?: StravaSync | null;
    activeRace?: ActiveRace | null;
    /** ISO-8601 timestamp of the auth user's last heart-rate-zone change, or null. */
    hrZonesChangedAt?: string | null;
    /** Whether the auth user has a live (non-revoked) Telegram connection. */
    telegramConnected?: boolean;
    /** Whether the auth user has at least one browser push subscription. */
    webPushSubscribed?: boolean;
    /** How many of the auth user's inbox notifications are still unread. */
    unreadNotifications?: number;
    /** The public VAPID key the browser needs to subscribe to web push; '' when unconfigured. */
    webPushPublicKey?: string;
    /** True when the auth user's Strava connection is live but lacks the `profile:read_all` scope needed for HR-zone sync. */
    stravaZoneScopeMissing?: boolean;
    /** True when LLM narration is globally paused, so the UI can show a soft "Temari lagi istirahat" banner. */
    aiPaused?: boolean;
    /** True when the auth user has an activity still waiting on its per-activity narration, so the UI can show a soft "still catching up" banner. */
    aiCatchingUp?: boolean;
    /** True when the Strava kill-switch is off, so every manual sync affordance hides behind a soft "lagi dijeda" banner. */
    stravaPaused?: boolean;
    /** Inertia's shared validation/error bag, keyed by field (e.g. `strava`). */
    errors?: Record<string, string>;
    [key: string]: unknown;
}

export interface AnalysisPayload {
    id: number | null;
    status: AnalysisStatus;
    content: string | null;
    type: AnalysisType;
    is_zone_dependent?: boolean;
    subject_type: string;
    subject_id: number;
    discriminator: string | null;
    attempts?: number;
    generated_at?: string | null;
    retry_after_seconds?: number | null;
}

export interface BriefingResult {
    vibeState: string;
    vibeLabel: string;
    vibeEmoji: string;
    mascotVoice: AnalysisPayload;
    featuredKartuVoice: AnalysisPayload;
    featuredCardId: number | null;
    recoveryLabel: string;
    recoveryTone: RecoveryTone;
    recoveryHoursLabel: string | null;
    /** Raw hours since the last run — the number `recoveryHoursLabel` is rendered from. */
    recoveryHours: number | null;
    streakLabel: string | null;
    sigilPattern: string;
    accessory: string | null;
    mood: Mood;
}

export interface StreamSummaryPerKm {
    km: number;
    pace: string; // "M:SS" per km
    avg_hr?: number | null;
    avg_cadence_spm?: number | null;
}

/** One lap as the watch recorded it — whatever distance the lap button (or the
 *  auto-split) made it, never bucketed into kilometres. Absent for runs Strava
 *  shipped no usable laps for. */
export interface StreamSummaryLap {
    lap: number;
    distance_m: number;
    elapsed_sec: number;
    pace: string; // "M:SS" normalized per km
    avg_hr?: number | null;
    avg_cadence_spm?: number | null;
}

/** % of moving time spent in each HR zone, keyed Z1..Z5. Absent for no-HR runs. */
export type ZonePct = Partial<Record<'Z1' | 'Z2' | 'Z3' | 'Z4' | 'Z5', number>>;

/** Minutes of moving time spent in each HR zone, keyed Z1..Z5. Absent for no-HR runs. */
export type ZoneMinutes = Partial<
    Record<'Z1' | 'Z2' | 'Z3' | 'Z4' | 'Z5', number>
>;

/** The trailing sub-km "sisa" segment. Display/narrative-only — never a full km
 *  and never in per_km (which stays full-km for card stats & AI). Absent for
 *  runs that end on a whole km. */
export interface StreamSummaryPartial {
    distance_m: number;
    pace: string; // "M:SS" normalized per km
    avg_hr?: number | null;
    avg_cadence_spm?: number | null;
}

/** Windows the producer writes a `best_<window>_pace` key for (StreamAnalysis::BEST_EFFORT_WINDOWS). */
export type BestEffortWindow =
    '30s' | '1min' | '3min' | '5min' | '10min' | '20min' | '30min' | '60min';

/** Share of moving time below / within / above the step-rate band. */
export type CadenceDistributionPct = Partial<
    Record<'<165' | '165-175' | '>175', number>
>;

/** Mirrors `StreamAnalysis::compute()` — every key is omitted (never null) when
 *  the stream it derives from is missing or too short. */
export interface StreamSummary {
    per_km?: StreamSummaryPerKm[];
    laps?: StreamSummaryLap[];
    partial_split?: StreamSummaryPartial | null;
    negative_split?: boolean;
    pace_variability_sec?: number;
    hr_drift_bpm?: number;
    cadence_drop_spm?: number;
    time_in_zone_pct?: ZonePct;
    time_in_zone_min?: ZoneMinutes;
    decoupling_pct?: number;
    cadence_distribution_pct?: CadenceDistributionPct;
    optimal_cadence_pct?: number;
    max_grade_pct?: number;
    climb_time_pct?: number;
    gap_pace?: string;
    descent_m?: number;
    stopped_time_sec?: number;
    stop_count?: number;
    best_30s_pace?: string;
    best_1min_pace?: string;
    best_3min_pace?: string;
    best_5min_pace?: string;
    best_10min_pace?: string;
    best_20min_pace?: string;
    best_30min_pace?: string;
    best_60min_pace?: string;
}

export interface ActivityDetail {
    id: number;
    activity_id: number;
    name: string | null;
    start_date_local: string | null;
    distance: number | null;
    elapsed_time: number | null;
    total_elevation_gain?: number | null;
    average_heartrate: number | null;
    max_heartrate?: number | null;
    /** Strava ships rpm (one leg); doubled for the spm the UI shows. */
    average_cadence?: number | null;
    trimp_edwards: number | null;
    workout_type?: number | null;
    location_name?: string | null;
    location_country?: string | null;
    weather_temp_c?: number | null;
    weather_humidity_pct?: number | null;
    weather_rain_detected?: boolean | null;
    weather_wind_speed_kmh?: number | null;
    weather_wind_gust_kmh?: number | null;
    weather_wind_direction_deg?: number | null;
    weather_rain_is_forecast?: boolean | null;
    summary_polyline?: string | null;
    stream_summary?: StreamSummary | null;
    activity?: Activity;
}

export interface Activity {
    id: number;
    user_id: number;
    name?: string;
    analyzed_at: string | null;
    ingest_state?: 'summary' | 'detailed';
    detail?: ActivityDetail;
    run_card?: RunCard;
}

export interface CardEdition {
    index: number;
    total: number;
}

export interface RunCard {
    id: number;
    activity_id: number;
    rarity: Rarity;
    special_move: string;
    badges: string[] | null;
    edition?: CardEdition | null;
    activity?: Activity;
}

export interface StoryLine {
    id: number;
    user_id: number;
    activity_id: number | null;
    kind: string;
    mood: Mood;
    speech: string | null;
    sigil_pattern: string;
    for_date: string | null;
}

export type FormStatus = 'fresh' | 'optimal' | 'fatigued' | 'overreaching';

/** The three week-window fields are null when the week's runs carried no heart
 *  rate, which is not the same as a zero. ATL/CTL/form are EWMAs over a year of
 *  history and stay numbers through an unscored stretch. */
export interface TrainingLoad {
    form: number;
    form_status: FormStatus;
    ctl_42d: number;
    atl_7d: number;
    weekly_trimp: number | null;
    monotony: number | null;
    strain: number | null;
}

export type TrendVerdict =
    'improving' | 'plateaued' | 'slipped' | 'not_enough_history';

export type TrendDirection = 'better' | 'flat' | 'worse';

/** One side of a Past You pair, as `ComparableRun::toArray()` ships it. */
export interface ComparableRun {
    activity_id: number;
    date: string;
    km: number;
    pace_sec_per_km: number;
    average_heartrate: number | null;
    elevation_gain_m: number | null;
    ingest_state: 'summary' | 'detailed';
}

/** One matched pair, as `PastYouComparison::toArray()` ships it. */
export interface PastYouComparison {
    direction: TrendDirection;
    days_apart: number;
    similarity: number;
    /** Positive when the recent run is faster. */
    pace_delta_sec: number;
    /** Negative when the recent run's average heart rate is lower. */
    hr_delta_bpm: number | null;
    current: ComparableRun;
    past: ComparableRun;
}

/** `PastYouTrend::toArray()`. Every supporting reading stays null until the
 *  detail pipeline has hydrated the runs the verdict was built from. */
export interface PastYouTrend {
    verdict: TrendVerdict;
    window_days: number;
    comparison_count: number;
    comparisons: PastYouComparison[];
    mean_pace_delta_sec: number | null;
    mean_hr_delta_bpm: number | null;
    fitness_delta_ctl: number | null;
    pace_consistency_now: string | null;
    pace_consistency_then: string | null;
    relative_effort_band: string | null;
}

/** One day within `WeekPlan['days']`, as `PlanRenderer::dayPayload()` ships
 *  it — the same shape Plan's own day rows use. */
/** One ordered slice of a planned session — see `App\Services\Run\Plan\SessionSegment`.
 *  `minutes`/`pace_sec_per_km` are null exactly when the athlete has no VDOT
 *  estimate yet; the segment's shape (key, pace target) still renders. */
export interface PlanSessionSegment {
    key: 'warmup' | 'main' | 'interval' | 'recovery' | 'cooldown';
    minutes: number | null;
    zone: string;
    pace_label: 'easy' | 'marathon' | 'threshold' | 'interval';
    pace_sec_per_km: number | null;
}

export interface WeekPlanDay {
    id: number;
    date: string;
    phase: string;
    session_type: string;
    /** Ordered warmup/main/cooldown (or interval reps) breakdown — see
     *  `App\Services\Run\Plan\SegmentGenerator`. Empty on a rest day. */
    segments: PlanSessionSegment[];
    /** The CORE work only (e.g. a Tempo day's threshold portion) — warmup/
     *  cooldown are additional minutes on top, not counted here. */
    distance_km: number;
    pinned: boolean;
    status: 'planned' | 'done' | 'partial' | 'missed';
    clamp_note: string | null;
}

/** `CurrentWeekPlanBuilder::forUser()` — Home's compact pull of the current
 *  week's plan, null once a user has no plan yet. */
export interface WeekPlan {
    sessions_per_week: number;
    phase: string;
    planned_km_this_week: number;
    credited_this_week: number;
    streak_days: number;
    days: WeekPlanDay[];
}

/** One `weekly_snapshots` row, shipped whole (`WeeklySnapshot::toArray()`). */
export interface WeeklySnapshot {
    id: number;
    user_id: number;
    week_ending: string;
    runs: number | null;
    distance_km: number | null;
    moving_time_sec?: number | null;
    weekly_trimp: number | null;
    ctl_42d: number | null;
    atl_7d: number | null;
    form: number | null;
    form_status: FormStatus | null;
    avg_decoupling: number | null;
    monotony: number | null;
    strain: number | null;
}

/** A snapshot decorated by RunController::decorateSnapshot with its recap
 *  narration and the flags the feed week list renders it with. */
export interface WeeklySnapshotWithRecap extends WeeklySnapshot {
    /** True for the in-progress week, whose recap waits for the weekly scheduler. */
    is_current_week: boolean;
    /** True for the latest completed week, the only chain link that may regenerate. */
    is_chain_head: boolean;
    recap_analysis: AnalysisPayload;
    /** Remaining Telegram-send cooldown for this week's recap, or null. */
    notification_retry_after_seconds: number | null;
}

export interface PersonalRecord {
    id: number;
    user_id: number;
    activity_id: number;
    category: string;
    value: number;
    activity?: Activity;
}

export interface PaginatedResponse<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}
