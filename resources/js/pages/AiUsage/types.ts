export interface UsageRow {
    kind: string;
    prompt: number;
    completion: number;
    total: number;
    calls: number;
    cost: number;
    truncated_calls: number;
    avg_latency_ms: number | null;
    max_latency_ms: number | null;
    /** Model turns per call. Above 1 means the agent loop called tools. */
    avg_steps: number | null;
    /** Share of prompt tokens the provider served from its cache. */
    cached_pct: number | null;
    /** Share of completion tokens spent reasoning rather than answering. */
    reasoning_pct: number | null;
}

export interface UsageTotals {
    prompt: number;
    completion: number;
    total: number;
    calls: number;
    cost: number;
    truncated_calls: number;
}

export interface UserRow {
    user_id: number;
    user_name: string | null;
    strava_athlete_id: number | null;
    /** The account is gone; name and athlete id are the snapshot taken on delete. */
    deleted: boolean;
    prompt: number;
    completion: number;
    total: number;
    calls: number;
}

export interface DailyRow {
    day: string;
    prompt: number;
    completion: number;
    total: number;
    calls: number;
    cost: number;
}

export interface DeploymentRow {
    deployment: string;
    prompt: number;
    completion: number;
    total: number;
    calls: number;
    cost: number;
    inputPer1m: number | null;
    outputPer1m: number | null;
}

export interface KindOption {
    value: string;
    label: string;
}

export interface Budget {
    todayCost: number;
    dailyCeiling: number | null;
    currency: string;
}

export interface DeadLetterBlock {
    type: string;
    error: string | null;
    failed_at: string;
}

export interface DeadLetterGroup {
    user_id: number;
    user_name: string;
    count: number;
    blocks: DeadLetterBlock[];
}

export type PreviousTotals = Omit<UsageTotals, 'truncated_calls'>;

/** Relative range token resolved server-side; drives preset highlighting. */
export type RangeToken = 'today' | '7d' | '30d' | 'month' | 'all' | 'custom';

export interface AiUsageProps {
    range: RangeToken;
    from: string;
    to: string;
    kind: string | null;
    totals: UsageTotals;
    previousTotals: PreviousTotals | null;
    byKind: UsageRow[];
    byUser: UserRow[];
    byDeployment: DeploymentRow[];
    daily: DailyRow[];
    availableKinds: KindOption[];
    budget: Budget;
    deadLettered: DeadLetterGroup[];
    failedUnderBudget: DeadLetterGroup[];
    nyangkut: DeadLetterGroup[];
}
