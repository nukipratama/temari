// Generated from the PHP enums — see generated.ts (`php artisan typescript:enums`).
// Imported for local use below and re-exported so consumers keep importing from here.
import type { AnalysisStatus, AnalysisType, Rarity } from './generated';

export type { AnalysisStatus, AnalysisType, Rarity } from './generated';

export type Mood = 'nyala' | 'enteng' | 'oleng' | 'lemes' | 'mumet' | 'adem';

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
    moving_time_sec: number | null;
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
export type EquippedSlot = 'medal' | 'ikat_kepala' | 'kaus' | 'celana' | 'sepatu' | 'aura';

export interface EquippedAccessories {
    medal: string | null;
    ikat_kepala: string | null;
    kaus: string | null;
    celana: string | null;
    sepatu: string | null;
    aura: string | null;
}

/** Flashed by UnlockEngine when a user earns their first new accessory in a request. */
export interface UnlockFlash {
    unlock_key: string;
    name: string;
    icon: string;
    is_major: boolean;
}

export interface SharedProps {
    auth: { user: AuthUser | null };
    flash: { success: string | null; error: string | null; info: string | null; unlock?: UnlockFlash | null };
    demoLoginEnabled: boolean;
    pendingReveal?: PendingReveal | null;
    equippedAccessories?: EquippedAccessories | null;
    stravaSync?: StravaSync | null;
    goalsSummary?: GoalsSummary | null;
    /** ISO-8601 timestamp of the auth user's last heart-rate-zone change, or null. */
    hrZonesChangedAt?: string | null;
    /** Whether the auth user has a live (non-revoked) Telegram connection. */
    telegramConnected?: boolean;
    /** Inertia's shared validation/error bag, keyed by field (e.g. `strava`). */
    errors?: Record<string, string>;
    [key: string]: unknown;
}

export interface GoalsSummaryItem {
    id: string;
    title: string;
    current: number;
    target: number;
    unit: string;
}

export interface GoalsSummary {
    total: number;
    completed: number;
    closest: GoalsSummaryItem[];
}

export interface WeeklyRecapBestCard {
    id: number;
    rarity: Rarity;
    special_move: string;
    mood: Mood | null;
    distance_km: number | null;
    polyline: string | null;
    date: string | null;
}

export interface WeeklyRecapNearestGoal {
    id: string;
    title: string;
    current: number;
    target: number;
    unit: string;
    ratio: number;
    remainder_label: string;
}

export interface WeeklyRecap {
    week_start: string;
    week_end: string;
    this_week_km: number;
    this_week_runs: number;
    last_week_km: number;
    /** Signed whole percent km change vs last week, or null when there is no comparable baseline. */
    delta_pct: number | null;
    streak_weeks: number;
    best_card: WeeklyRecapBestCard | null;
    nearest_goal: WeeklyRecapNearestGoal | null;
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
    headline: AnalysisPayload;
    suggestion: AnalysisPayload;
    mascotVoice: AnalysisPayload;
    featuredKartuVoice: AnalysisPayload;
    featuredCardId: number | null;
    recoveryLabel: string;
    recoveryTone: RecoveryTone;
    recoveryHoursLabel: string | null;
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

/** % of moving time spent in each HR zone, keyed Z1..Z5. Absent for no-HR runs. */
export type ZonePct = Partial<Record<'Z1' | 'Z2' | 'Z3' | 'Z4' | 'Z5', number>>;

export interface StreamSummary {
    per_km?: StreamSummaryPerKm[];
    negative_split?: boolean;
    time_in_zone_pct?: ZonePct;
    [key: string]: unknown;
}

export interface ActivityDetail {
    id: number;
    activity_id: number;
    name: string | null;
    start_date_local: string | null;
    distance: number | null;
    moving_time: number | null;
    total_elevation_gain?: number | null;
    average_heartrate: number | null;
    trimp_edwards: number | null;
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

export interface TrainingLoad {
    form: number;
    form_status: FormStatus;
    ctl_42d: number;
    atl_7d: number;
    weekly_trimp: number;
    monotony: number;
    strain: number;
}

export interface WeeklySnapshot {
    id: number;
    user_id: number;
    week_ending: string;
    runs: number;
    distance_km: number | null;
    ctl_42d: number | null;
    atl_7d: number | null;
    form: number | null;
    avg_decoupling: number | null;
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
