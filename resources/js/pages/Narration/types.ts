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

/**
 * Spend split by what started the call. `kind` names the narrator, so it cannot
 * say whether a run_insight row came from the ingest cascade, a "Reread", or the
 * hourly self-heal; this is that missing dimension.
 */
export interface OriginRow {
    origin: string;
    label: string;
    prompt: number;
    completion: number;
    total: number;
    calls: number;
    cost: number;
}

export interface Budget {
    todayCost: number;
    /**
     * Combined figure: perUserCeiling x athletes. Derived, not a limit of its
     * own: what the bill would reach if every athlete spent their slice.
     */
    dailyCeiling: number | null;
    /** The enforced per-athlete daily ceiling. */
    perUserCeiling: number | null;
    /** The enforced app-wide daily ceiling, which binds before the combined figure. */
    totalCeiling: number | null;
    /** Non-demo athletes the combined ceiling is derived from. */
    athletes: number;
    currency: string;
    /** ISO8601 local time the ceiling first tripped today; null if it hasn't. */
    trippedAt: string | null;
    degradedFills: number;
}

export interface ContentFilterSummary {
    /** Azure output-side content-filter trips that degraded to rule-based content, in range. */
    trips: number;
    /** Share of calls in range that tripped, or null when there were no calls. */
    pct: number | null;
}

/** One day of spend, split by the narrator kind that billed it. */
export interface ChartDay {
    day: string;
    cost: number;
    byKind: Record<string, number>;
}

export interface ChartKind {
    kind: string;
    label: string;
    cost: number;
}

export interface CostChart {
    /** Kinds present in the range, most expensive first, which is the stack order. */
    kinds: ChartKind[];
    days: ChartDay[];
}

export interface SparklinePoint {
    day: string;
    cost: number;
}

/** How a period's Done narration was produced, per athlete. */
export interface ServedSplit {
    llm: number;
    rule_based: number;
    /** Narrated before `served_by` existed. Not the same as rule-based. */
    unknown: number;
}

export interface AthleteRow {
    user_id: number;
    user_name: string | null;
    is_demo: boolean;
    /** The account is gone; the name is the snapshot taken on delete. */
    deleted: boolean;
    today: number;
    last7: number;
    last30: number;
    calls: number;
    /** The athlete's effective daily ceiling, override included. */
    ceiling: number | null;
    ceiling_overridden: boolean;
    capped: boolean;
    sparkline: SparklinePoint[];
    served: ServedSplit;
    flags: number;
    dead_lettered: number;
}

export type PreviousTotals = Omit<UsageTotals, 'truncated_calls'>;

/** Relative range token resolved server-side; drives preset highlighting. */
export type RangeToken = 'today' | '7d' | '30d' | 'month' | 'all' | 'custom';

export interface NarrationOverviewProps {
    range: RangeToken;
    from: string;
    to: string;
    kind: string | null;
    origin: string | null;
    /** The athlete the cost chart is narrowed to, or null for all of them. */
    athlete: number | null;
    totals: UsageTotals;
    previousTotals: PreviousTotals | null;
    byKind: UsageRow[];
    byDeployment: DeploymentRow[];
    byOrigin: OriginRow[];
    availableKinds: KindOption[];
    availableOrigins: KindOption[];
    budget: Budget;
    contentFilter: ContentFilterSummary;
    chart: CostChart;
    athletes: AthleteRow[];
    cappedToday: number;
    /** Why auto-dispatch is stopped right now, or null when it is running. */
    pauseReason: string | null;
}

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

export interface CeilingOverride {
    value: number;
    expires_at: string;
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
    override: CeilingOverride | null;
    replayBudget: ReplayBudget;
}
