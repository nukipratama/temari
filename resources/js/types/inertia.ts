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
    aiActivity?: { pending: number; queued: number; processing: number };
    [key: string]: unknown;
}

export type AnalysisStatus = 'pending' | 'queued' | 'processing' | 'done' | 'failed';

export type AnalysisType =
    | 'briefing_headline'
    | 'briefing_suggestion'
    | 'post_run_speech'
    | 'daily_greeting'
    | 'run_insight_technical'
    | 'run_insight_splits'
    | 'run_insight_zones'
    | 'weekly_recap'
    | 'pr_context'
    | 'trend_caption'
    | 'card_flavor';

export interface AnalysisPayload {
    id: number | null;
    status: AnalysisStatus;
    content: string | null;
    type: AnalysisType;
    subject_type: string;
    subject_id: number;
    discriminator: string | null;
}

export interface BriefingResult {
    vibeState: string;
    vibeLabel: string;
    vibeEmoji: string;
    headline: AnalysisPayload;
    suggestion: AnalysisPayload;
    recoveryLabel: string;
    recoveryTone: RecoveryTone;
    streakLabel: string | null;
    sigilPattern: string;
    accessory: string | null;
    mood: Mood;
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
