/**
 * Shared TS types for Inertia props from teman-lari controllers.
 * Mirrors PHP DTOs in app/Services/Run/Story/* and Eloquent models.
 */

export type Mood = 'glow' | 'bouncy' | 'wobble' | 'squished' | 'spinning' | 'dim';

export type Tone = 'neutral' | 'positive' | 'warning' | 'alert';

export type RecoveryTone = 'positive' | 'warning' | 'alert' | 'neutral';

export interface AuthUser {
    id: number;
    name: string;
    first_name: string;
    avatar_url: string | null;
}

export interface SharedProps {
    auth: { user: AuthUser | null };
    flash: { success: string | null; error: string | null; info: string | null };
    demoLoginEnabled: boolean;
    onboarding: { forceShow: boolean };
}

export interface BriefingResult {
    vibeState: string;
    vibeLabel: string;
    vibeEmoji: string;
    headlineLine: string;
    suggestionLine: string;
    recoveryLabel: string;
    recoveryTone: RecoveryTone;
    streakLabel: string | null;
    sigilPattern: string;
    accessory: string | null;
    mood: Mood;
    degraded?: boolean;
}

export interface VerdictTimelineItem {
    activityId: number;
    mood: Mood;
    moodFace: string;
    oneline: string;
    startedAt: string;
    distanceKm: number;
    degraded?: boolean;
}

export interface ActivityDetail {
    id: number;
    activity_id: number;
    name: string | null;
    start_date_local: string | null;
    distance: number | null;
    moving_time: number | null;
    average_heartrate: number | null;
    trimp_edwards: number | null;
    location_name?: string | null;
    location_country?: string | null;
    summary_polyline?: string | null;
}

export interface Activity {
    id: number;
    user_id: number;
    name?: string;
    analyzed_at: string | null;
    detail?: ActivityDetail;
    runCard?: RunCard;
}

export type Rarity = 'biasa' | 'jarang' | 'langka' | 'epik' | 'legendaris';

export interface RunCard {
    id: number;
    activity_id: number;
    rarity: Rarity;
    special_move: string;
    badges: string[] | null;
    activity?: Activity;
}

export interface StoryLine {
    id: number;
    user_id: number;
    activity_id: number | null;
    kind: string;
    mood: Mood;
    speech: string;
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

export interface FitnessChartData {
    labels: string[];
    ctl: (number | null)[];
    atl: (number | null)[];
    form: (number | null)[];
    volume: (number | null)[];
}

export interface PaginatedResponse<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}
