export interface CostBucket {
    cost: number;
    calls: number;
}

export interface AthleteToolCall {
    tool: string;
    arguments_summary: string;
    duration_ms: number;
}

export interface NarrationFlag {
    reason: string | null;
    note: string | null;
    at: string | null;
}

export interface NarrationRow {
    id: number;
    kind: string;
    discriminator: string | null;
    status: string;
    served_by: string | null;
    origin: string | null;
    cost: number;
    last_cost: number;
    prompt_tokens: number;
    completion_tokens: number;
    latency_ms: number | null;
    steps: number;
    tool_calls: AthleteToolCall[];
    content: string | null;
    error: string | null;
    generated_at: string | null;
    flag: NarrationFlag | null;
    version_count: number;
    previous_content: string | null;
}

export interface AthleteHeaderData {
    athlete: { id: number; name: string; is_demo: boolean };
    currency: string;
    today_spend: number;
    ceiling: { value: number | null; source: string };
    sparkline: Array<{ day: string; cost: number }>;
    forecast: {
        month_to_date: number;
        projected: number;
        days_remaining: number;
        daily_rate: number;
    };
}

export interface CostByKindRow {
    kind: string;
    today: CostBucket;
    week: CostBucket;
    month: CostBucket;
}

export interface AttentionBlock {
    id: number;
    kind: string;
    status: string;
    attempts: number;
    error: string | null;
    at: string;
}

export interface AttentionData {
    failed: AttentionBlock[];
    dead_lettered: AttentionBlock[];
    stuck: AttentionBlock[];
}

export interface AuditRow {
    actor: string;
    action: string;
    payload: Record<string, unknown> | null;
    at: string;
}

export interface ReplayBudget {
    cap: number | null;
    spent_today: number;
    cap_reached: boolean;
}

export type AthleteTab = 'narrations' | 'cost' | 'attention';

export interface AthletePageProps {
    tab: AthleteTab;
    filters: {
        kind: string | null;
        status: string | null;
        before: number | null;
    };
    availableKinds: string[];
    availableStatuses: string[];
    header: AthleteHeaderData;
    narrations: NarrationRow[];
    nextCursor: number | null;
    costByKind: CostByKindRow[];
    attention: AttentionData;
    audit: AuditRow[];
    override: { value: number; expires_at: string } | null;
    replayBudget: ReplayBudget;
}
