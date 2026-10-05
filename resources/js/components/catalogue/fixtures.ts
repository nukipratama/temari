import type { PlanDay } from '@/lib/plan';
import type {
    AnalysisPayload,
    AuthUser,
    WeeklySnapshotWithRecap,
} from '@/types/inertia';

/** Subject ids no record has, so a control pressed in the catalogue reaches the server and is refused. */
const NO_SUBJECT = 0;

export const RUNNER: AuthUser = {
    id: NO_SUBJECT,
    name: 'Ada Lovelace',
    first_name: 'Ada',
    avatar_url: null,
    is_demo: false,
};

export const DEMO_RUNNER: AuthUser = { ...RUNNER, is_demo: true };

export const NARRATION =
    "13.5 km this week, down from 23.6 last week. the last few days have been **heavy**, so I'm keeping today to an easy run, 30-40 minutes.";

export function analysis(
    overrides: Partial<AnalysisPayload> = {},
): AnalysisPayload {
    return {
        id: null,
        status: 'done',
        content: NARRATION,
        type: 'weekly_recap',
        subject_type: 'weekly_snapshot',
        subject_id: NO_SUBJECT,
        discriminator: null,
        generated_at: '2026-09-28T06:10:00+07:00',
        ...overrides,
    };
}

export function planDay(overrides: Partial<PlanDay> = {}): PlanDay {
    return {
        id: NO_SUBJECT,
        date: '2026-09-29',
        phase: 'base',
        session_type: 'easy',
        segments: [],
        distance_km: 6,
        asked_km: 6,
        pinned: false,
        skipped: false,
        status: 'planned',
        compliance_score: null,
        ran_anyway: false,
        prescribed_km: null,
        prescription_reason: null,
        fall_off_tilt: null,
        goal_pace: null,
        time_trial: null,
        advice_note: null,
        eased_from: null,
        pace_eased_from: null,
        credit_note: null,
        ran_hot: false,
        result_note: null,
        ran_pace_sec_per_km: null,
        actual_km: null,
        credited_km: null,
        activities: [],
        ...overrides,
    };
}

export function weeklySnapshot(
    overrides: Partial<WeeklySnapshotWithRecap> = {},
): WeeklySnapshotWithRecap {
    return {
        id: NO_SUBJECT,
        user_id: NO_SUBJECT,
        week_ending: '2026-09-28',
        runs: 4,
        distance_km: 31.2,
        weekly_trimp: 212,
        ctl_42d: 38.4,
        atl_7d: 46.9,
        form: -8.4,
        form_status: 'fatigued',
        avg_decoupling: null,
        avg_decoupling_v2: 6.2,
        monotony: 1.2,
        strain: 254,
        is_current_week: false,
        is_chain_head: true,
        recap_analysis: analysis(),
        ...overrides,
    };
}
