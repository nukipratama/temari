import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import { Icon } from '@iconify/react';
import { formatMonthDayId, formatWeekdayDayId } from '@/lib/pace';
import SectionHeading from '@/components/SectionHeading';
import KpiTile from '@/components/dashboard/KpiTile';
import PageContainer from '@/components/ui/PageContainer';
import Card from '@/components/ui/Card';
import PillButton from '@/components/ui/PillButton';
import ProgressBar from '@/components/ui/ProgressBar';
import { cn } from '@/lib/cn';
import { toggleButtonVariants } from '@/lib/variants';
import type { SharedProps } from '@/types/inertia';

interface UsageRow {
    kind: string;
    prompt: number;
    completion: number;
    total: number;
    calls: number;
    cost: number;
    truncated_calls: number;
    avg_latency_ms: number | null;
    max_latency_ms: number | null;
}

interface UsageTotals {
    prompt: number;
    completion: number;
    total: number;
    calls: number;
    cost: number;
    truncated_calls: number;
}

interface UserRow {
    user_id: number;
    user_name: string | null;
    prompt: number;
    completion: number;
    total: number;
    calls: number;
}

interface DailyRow {
    day: string;
    prompt: number;
    completion: number;
    total: number;
    calls: number;
    cost: number;
}

interface DeploymentRow {
    deployment: string;
    prompt: number;
    completion: number;
    total: number;
    calls: number;
    cost: number;
    inputPer1m: number | null;
    outputPer1m: number | null;
}

interface KindOption {
    value: string;
    label: string;
}

interface Budget {
    todayCost: number;
    dailyCeiling: number | null;
    currency: string;
}

interface DeadLetterBlock {
    type: string;
    error: string | null;
    failed_at: string;
}

interface DeadLetterGroup {
    user_id: number;
    user_name: string;
    count: number;
    blocks: DeadLetterBlock[];
}

type PreviousTotals = Omit<UsageTotals, 'truncated_calls'>;

/** Relative range token resolved server-side; drives preset highlighting. */
type RangeToken = 'today' | '7d' | '30d' | 'month' | 'all' | 'custom';

interface AiUsageProps {
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
}

const numberFmt = new Intl.NumberFormat('id-ID');

function fmt(n: number): string {
    return numberFmt.format(n);
}

/** Format a cost as a currency string, scaled to the budget's currency. */
function formatCost(amount: number, currency: string): string {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency,
        currencyDisplay: 'narrowSymbol',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount);
}

/**
 * Navigate the report. A preset range travels as a self-correcting `range`
 * token (resolved server-side, never stale); a custom Dari/Sampai window
 * travels as absolute `from`/`to`.
 */
function navigate({ range, from, to, kind }: { range: RangeToken; from: string; to: string; kind: string | null }): void {
    const params: Record<string, string> = {};
    if (range === 'custom') {
        params.from = from;
        params.to = to;
    } else {
        params.range = range;
    }
    if (kind !== null) {
        params.kind = kind;
    }
    router.get('/ai-usage', params, { preserveState: true, preserveScroll: true });
}

/** Build a durable, date-free preset href that preserves the active kind filter. */
function presetHref(token: RangeToken, kind: string | null): string {
    const params = new URLSearchParams({ range: token });
    if (kind !== null) {
        params.set('kind', kind);
    }
    return `/ai-usage?${params.toString()}`;
}

const PRESETS: ReadonlyArray<{ token: RangeToken; label: string }> = [
    { token: 'today', label: 'Hari ini' },
    { token: '7d', label: '7 hari' },
    { token: '30d', label: '30 hari' },
    { token: 'month', label: 'Bulan ini' },
    { token: 'all', label: 'Semua' },
];

function formatDayLabel(day: string): string {
    return formatMonthDayId(new Date(day + 'T00:00:00'));
}

function formatDayLabelShort(day: string): string {
    return formatWeekdayDayId(new Date(day + 'T00:00:00'));
}

export default function AiUsage({
    range,
    from,
    to,
    kind,
    totals,
    previousTotals,
    byKind,
    byUser,
    byDeployment,
    daily,
    availableKinds,
    budget,
    deadLettered,
}: Readonly<AiUsageProps>) {
    const [fromInput, setFromInput] = useState<string>(from);
    const [toInput, setToInput] = useState<string>(to);
    const [kindInput, setKindInput] = useState<string>(kind ?? '');
    const flashInfo = usePage<SharedProps>().props.flash?.info;

    const promptShare = totals.total > 0 ? Math.round((totals.prompt / totals.total) * 100) : 0;
    const avgPerCall = totals.calls > 0 ? Math.round(totals.total / totals.calls) : 0;
    const truncatedShare = totals.calls > 0 ? Math.round((totals.truncated_calls / totals.calls) * 100) : 0;
    const currency = budget.currency;

    function handleSubmit(e: React.FormEvent): void {
        e.preventDefault();
        // Editing the date fields is a custom window.
        navigate({ range: 'custom', from: fromInput, to: toInput, kind: kindInput || null });
    }

    // Changing the kind applies immediately, keeping the current range window.
    function handleKindChange(value: string): void {
        setKindInput(value);
        navigate({ range, from, to, kind: value || null });
    }

    return (
        <div className="min-h-screen bg-surface text-ink">
            <Head title="AI Usage" />

            <header className="border-b border-line bg-surface-elev">
                <div className="mx-auto flex max-w-page items-center justify-between px-6 py-4 2xl:max-w-page-2xl">
                    <div className="flex items-center gap-3">
                        <span className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-leaf-deep text-cream">
                            <Icon icon="mdi:counter" width={20} aria-hidden />
                        </span>
                        <div>
                            <h1 className="text-headline-xs font-semibold tracking-tight text-ink">AI Usage</h1>
                            <p className="text-xs text-ink-3">Konsumsi token Azure OpenAI per rentang tanggal.</p>
                        </div>
                    </div>
                    <span className="hidden text-label-micro font-semibold text-ink-3 sm:inline">
                        TemanLari · Devtools
                    </span>
                </div>
            </header>

            <PageContainer>
                {flashInfo && <FlashBanner message={flashInfo} />}

                <Card
                    as="section"
                    tone="cream"
                    padding="sm"
                    className="bg-surface-elev"
                >
                    <form onSubmit={handleSubmit} className="flex flex-wrap items-end gap-3">
                        <DateField id="from" label="Dari" value={fromInput} onChange={setFromInput} />
                        <DateField id="to" label="Sampai" value={toInput} onChange={setToInput} />

                        {availableKinds.length > 0 && (
                            <KindFilter kinds={availableKinds} value={kindInput} onChange={handleKindChange} />
                        )}

                        <PillButton type="submit" tone="sky" size="sm">
                            <Icon icon="mdi:filter-variant" aria-hidden />
                            <span>Terapkan</span>
                        </PillButton>

                        <div className="ml-auto flex flex-wrap gap-2">
                            {PRESETS.map((preset) => (
                                <PresetButton
                                    key={preset.token}
                                    label={preset.label}
                                    href={presetHref(preset.token, kind)}
                                    active={range === preset.token}
                                />
                            ))}
                        </div>
                    </form>
                </Card>

                <p className="mt-3 text-xs text-ink-3">
                    Rentang aktif: <span className="font-semibold text-ink">{from}</span> sampai{' '}
                    <span className="font-semibold text-ink">{to}</span>
                    {kind && (
                        <>
                            {' '}
                            <span className="text-ink-2">|</span>{' '}
                            Filter: <span className="font-semibold text-ink">{kind}</span>
                        </>
                    )}
                </p>

                <section className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <KpiTile
                        label="Total Tokens"
                        value={fmt(totals.total)}
                        sub={
                            <>
                                {totals.calls} call
                                <DeltaChip current={totals.total} previous={previousTotals?.total ?? null} />
                            </>
                        }
                    />
                    <KpiTile
                        label="Estimasi Biaya"
                        value={formatCost(totals.cost, currency)}
                        sub={
                            <>
                                {`${fmt(avgPerCall)} token/call`}
                                <DeltaChip current={totals.cost} previous={previousTotals?.cost ?? null} />
                            </>
                        }
                    />
                    <KpiTile label="Prompt" value={fmt(totals.prompt)} sub={`${promptShare}% dari total`} />
                    <KpiTile
                        label="Terpotong"
                        value={`${truncatedShare}%`}
                        sub={`${totals.truncated_calls} dari ${totals.calls} call`}
                        tone={truncatedShare > 1 ? 'alert' : 'neutral'}
                    />
                </section>

                <BudgetGauge budget={budget} />

                <DeadLetterPanel groups={deadLettered} />

                {daily.length > 0 && (
                    <section className="mt-10">
                        <SectionHeading
                            icon="mdi:chart-bar"
                            title="Konsumsi Harian"
                            subtitle="Token per hari dalam rentang yang dipilih."
                            tone="accent"
                        />

                        <DailyChart data={daily} currency={currency} />
                    </section>
                )}

                <DataTable
                    icon="mdi:server"
                    title="Breakdown per Deployment"
                    subtitle="Biaya per model Azure yang dipanggil."
                    tone="accent"
                    columns={['Deployment', 'Harga in/out /1M', 'Panggilan', 'Prompt', 'Completion', 'Total', 'Biaya']}
                    minWidth={640}
                    rows={byDeployment}
                    rowKey={(row) => row.deployment}
                    renderRow={(row) => (
                        <>
                            <Td className="font-medium text-ink">{row.deployment}</Td>
                            <Td className="whitespace-nowrap text-ink-2">
                                {row.inputPer1m === null || row.outputPer1m === null
                                    ? '—'
                                    : `${formatCost(row.inputPer1m, currency)} / ${formatCost(row.outputPer1m, currency)}`}
                            </Td>
                            <Td>{fmt(row.calls)}</Td>
                            <Td>{fmt(row.prompt)}</Td>
                            <Td>{fmt(row.completion)}</Td>
                            <Td className="font-semibold text-ink">{fmt(row.total)}</Td>
                            <Td className="font-semibold text-ink">{formatCost(row.cost, currency)}</Td>
                        </>
                    )}
                />

                <DataTable
                    icon="mdi:shape"
                    title="Breakdown per Kind"
                    subtitle="Jenis analisis yang paling banyak makan token."
                    tone="brand"
                    columns={['Jenis', 'Panggilan', 'Prompt', 'Completion', 'Total', 'Biaya', 'Latency (avg/max)', 'Terpotong']}
                    minWidth={760}
                    rows={byKind}
                    rowKey={(row) => row.kind}
                    renderRow={(row) => <KindCells row={row} grandTotal={totals.total} currency={currency} />}
                />

                <DataTable
                    icon="mdi:account-multiple"
                    title="Breakdown per User"
                    subtitle="Pengguna yang paling banyak mengajak Temari mengobrol."
                    tone="accent"
                    columns={['User', 'Panggilan', 'Prompt', 'Completion', 'Total', 'Rata-rata']}
                    minWidth={520}
                    rows={byUser}
                    rowKey={(row) => row.user_id}
                    renderRow={(row) => <UserCells row={row} grandTotal={totals.total} />}
                />
            </PageContainer>
        </div>
    );
}

/* ─── Flash banner ────────────────────────────────────────────────── */

/** Inline confirmation for a `back()->with('info', …)` flash (e.g. a retry
 * confirmation). This page renders standalone, not under AppShell, so it
 * reads `usePage().props.flash` itself rather than relying on a shared toast. */
function FlashBanner({ message }: Readonly<{ message: string }>) {
    const [dismissed, setDismissed] = useState(false);

    if (dismissed) {
        return null;
    }

    return (
        <div className="mb-4 flex items-center justify-between gap-3 rounded-2xl border border-line bg-surface-elev px-4 py-3 text-sm text-ink">
            <span>{message}</span>
            <button
                type="button"
                onClick={() => setDismissed(true)}
                aria-label="Tutup"
                className="focus-ring shrink-0 rounded-full p-1 text-ink-3 hover:text-ink"
            >
                <Icon icon="mdi:close" width={16} aria-hidden />
            </button>
        </div>
    );
}

/* ─── Dead-letter panel ───────────────────────────────────────────── */

/**
 * Blocks that ai:self-heal gave up on (Failed past the retry budget). Grouped
 * per user with a single "Coba lagi semua" that re-arms and re-dispatches all of
 * that user's stuck blocks. Hidden entirely when nothing is stuck.
 */
function DeadLetterPanel({ groups }: Readonly<{ groups: DeadLetterGroup[] }>) {
    if (groups.length === 0) {
        return null;
    }

    return (
        <section className="mt-10">
            <SectionHeading
                icon="mdi:alert-circle-outline"
                title="Perlu perhatian"
                subtitle="Blok AI yang gagal berulang dan berhenti dicoba otomatis. Coba lagi manual per user."
                tone="accent"
            />

            <div className="mt-4 space-y-3">
                {groups.map((group) => (
                    <DeadLetterGroupRow key={group.user_id} group={group} />
                ))}
            </div>
        </section>
    );
}

function DeadLetterGroupRow({ group }: Readonly<{ group: DeadLetterGroup }>) {
    const { post, processing } = useForm();

    function retry(): void {
        post(`/ai-usage/users/${group.user_id}/retry-failed`, { preserveScroll: true });
    }

    return (
        <div className="rounded-2xl border border-line bg-surface-elev p-4">
            <div className="flex items-center justify-between gap-3">
                <div className="min-w-0">
                    <p className="truncate font-medium text-ink">{group.user_name}</p>
                    <p className="text-xs text-ink-3">{group.count} blok berhenti dicoba otomatis</p>
                </div>
                <button
                    type="button"
                    onClick={retry}
                    disabled={processing}
                    className="focus-ring inline-flex shrink-0 items-center gap-1.5 rounded-full bg-leaf-deep px-3 py-1.5 text-xs font-semibold text-cream transition-opacity hover:opacity-90 disabled:cursor-wait disabled:opacity-60"
                >
                    <Icon icon="mdi:auto-awesome" aria-hidden />
                    <span>{processing ? 'Mengirim…' : 'Coba lagi semua'}</span>
                </button>
            </div>

            <ul className="mt-3 space-y-1 border-t border-line pt-3">
                {group.blocks.map((block, i) => (
                    <li key={`${block.type}-${i}`} className="flex flex-wrap items-baseline gap-x-2">
                        <span className="font-mono text-[11px] font-bold uppercase tracking-wider text-ink-3">{block.type}</span>
                        {block.error && <span className="min-w-0 truncate text-xs text-ink-2">{block.error}</span>}
                    </li>
                ))}
            </ul>
        </div>
    );
}

/* ─── Budget Gauge ────────────────────────────────────────────────── */

function BudgetGauge({ budget }: Readonly<{ budget: Budget }>) {
    const { todayCost, dailyCeiling, currency } = budget;
    const hasCeiling = dailyCeiling !== null && dailyCeiling > 0;
    const ratio = hasCeiling ? todayCost / dailyCeiling : 0;
    const overBudget = hasCeiling && ratio > 1;
    const caveat = 'Estimasi memakai harga list price dari config, bukan tagihan final.';

    return (
        <Card as="section" tone="cream" padding="md" className="mt-6 bg-surface-elev">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="text-label-micro text-ink-2">Anggaran Hari Ini</span>
                <span className="text-sm text-ink-2">
                    <span className="font-semibold text-ink">{formatCost(todayCost, currency)}</span>
                    {hasCeiling ? (
                        <>
                            {' / '}
                            {formatCost(dailyCeiling, currency)}
                        </>
                    ) : (
                        <span className="text-ink-3"> · tanpa batas</span>
                    )}
                </span>
            </div>

            {hasCeiling ? (
                <ProgressBar
                    value={ratio}
                    tone={overBudget ? 'sky' : 'horizon'}
                    ariaLabel={`Anggaran hari ini: ${Math.round(ratio * 100)}% terpakai`}
                    className="mt-3"
                />
            ) : (
                <p className="mt-3 text-xs text-ink-3">Tidak ada batas harian yang disetel.</p>
            )}

            {overBudget && (
                <p className="mt-2 text-xs font-semibold text-mood-lemes">
                    Melewati batas harian sebesar {formatCost(todayCost - dailyCeiling, currency)}.
                </p>
            )}

            <p className="mt-3 text-xs text-ink-3">{caveat}</p>
        </Card>
    );
}

/* ─── Daily Bar Chart ─────────────────────────────────────────────── */

function DailyChart({ data, currency }: Readonly<{ data: DailyRow[]; currency: string }>) {
    const maxTotal = Math.max(...data.map((d) => d.total), 1);
    const totalCost = data.reduce((sum, d) => sum + d.cost, 0);

    return (
        <Card tone="cream" padding="md" className="mt-4 bg-surface-elev">
            <div className="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                <span className="text-label-small text-ink-3">{data.length} hari</span>
                <span className="text-sm text-ink-2">
                    Estimasi biaya: <span className="font-semibold text-ink">{formatCost(totalCost, currency)}</span>
                </span>
            </div>

            <div className="flex gap-1.5" style={{ height: 180 }}>
                {data.map((d) => {
                    const heightPct = Math.max((d.total / maxTotal) * 100, 2);

                    return (
                        <div
                            key={d.day}
                            className="group relative flex flex-1 flex-col items-center justify-end"
                            style={{ minWidth: 0 }}
                        >
                            {/* Tooltip */}
                            <div className="pointer-events-none absolute bottom-full left-1/2 z-10 mb-2 -translate-x-1/2 whitespace-nowrap rounded-lg border border-line bg-surface-elev px-3 py-2 text-xs opacity-0 shadow-lg transition-opacity group-hover:opacity-100">
                                <div className="font-semibold text-ink">{formatDayLabel(d.day)}</div>
                                <div className="text-ink-2">{fmt(d.total)} token</div>
                                <div className="text-ink-3">{d.calls} call</div>
                                <div className="text-ink-3">{formatCost(d.cost, currency)}</div>
                            </div>

                            {/* Bar */}
                            <div
                                className="w-full rounded-t-sm bg-horizon transition-colors group-hover:bg-horizon-deep"
                                style={{ height: `${heightPct}%` }}
                                aria-label={`${formatDayLabel(d.day)}: ${fmt(d.total)} token`}
                            />
                        </div>
                    );
                })}
            </div>

            {/* X-axis labels */}
            <div className="mt-2 flex gap-1.5">
                {data.map((d) => (
                    <div key={d.day} className="flex-1 text-center">
                        {data.length <= 14 ? (
                            <span className="text-meta">{formatDayLabelShort(d.day)}</span>
                        ) : (
                            <span
                                className="text-meta block truncate"
                                title={formatDayLabel(d.day)}
                            >
                                {formatDayLabel(d.day)}
                            </span>
                        )}
                    </div>
                ))}
            </div>
        </Card>
    );
}

/* ─── Reusable Data Table ─────────────────────────────────────────── */

interface DataTableProps<T> {
    icon: string;
    title: string;
    subtitle: string;
    tone: 'brand' | 'accent';
    columns: readonly string[];
    /** Floor width so the table scrolls (not clips) on mobile. */
    minWidth: number;
    rows: readonly T[];
    rowKey: (row: T) => string | number;
    renderRow: (row: T) => ReactNode;
}

function DataTable<T>({ icon, title, subtitle, tone, columns, minWidth, rows, rowKey, renderRow }: Readonly<DataTableProps<T>>) {
    return (
        <section className="mt-10">
            <SectionHeading icon={icon} title={title} subtitle={subtitle} tone={tone} />

            {rows.length === 0 ? (
                <EmptyState />
            ) : (
                <div className="relative mt-4">
                    <Card tone="cream" padding="none" className="overflow-x-auto bg-surface-elev">
                        <table className="w-full text-sm tabular-nums" style={{ minWidth }}>
                            <thead>
                                <tr className="border-b border-line text-left text-xs text-ink-3">
                                    {columns.map((label) => (
                                        <th key={label} className="px-5 py-3 font-semibold">
                                            {label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr key={rowKey(row)} className="border-b border-line last:border-b-0">
                                        {renderRow(row)}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </Card>
                    {/* Hints the table scrolls horizontally on narrow viewports. Must be a
                        sibling of the overflow-x-auto card, not a descendant — a descendant
                        scrolls away with the table content instead of staying pinned to the
                        visible edge (verified live: an earlier attempt inside the card moved
                        with scrollLeft). */}
                    <div aria-hidden className="pointer-events-none absolute inset-y-0 right-0 w-8 bg-gradient-to-l from-surface-elev to-transparent" />
                </div>
            )}
        </section>
    );
}

function Td({ children, className }: Readonly<{ children: ReactNode; className?: string }>) {
    return <td className={cn('px-5 py-3 text-ink-2', className)}>{children}</td>;
}

/* ─── Kind Filter Dropdown ────────────────────────────────────────── */

function KindFilter({
    kinds,
    value,
    onChange,
}: Readonly<{ kinds: KindOption[]; value: string; onChange: (v: string) => void }>) {
    return (
        <label
            htmlFor="kind-filter"
            className="flex flex-col gap-1 font-mono text-xs font-bold uppercase tracking-wider text-ink-2"
        >
            Jenis
            <select
                id="kind-filter"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="rounded-xl border border-line bg-surface-sunken px-3 py-2 text-sm font-medium text-ink focus:border-leaf focus:outline-none"
            >
                <option value="">Semua</option>
                {kinds.map((k) => (
                    <option key={k.value} value={k.value}>
                        {k.label}
                    </option>
                ))}
            </select>
        </label>
    );
}

/* ─── Row cell groups ─────────────────────────────────────────────── */

function UserCells({ row, grandTotal }: Readonly<{ row: UserRow; grandTotal: number }>) {
    const share = grandTotal > 0 ? row.total / grandTotal : 0;
    const avg = row.calls > 0 ? Math.round(row.total / row.calls) : 0;
    const label = row.user_name ?? `User #${row.user_id}`;

    return (
        <>
            <td className="px-5 py-3 font-medium text-ink">
                <div>{label}</div>
                <ProgressBar
                    value={share}
                    size="sm"
                    ariaLabel={`${(share * 100).toFixed(1)}% dari total`}
                    className="mt-1 max-w-[160px]"
                />
            </td>
            <Td>{fmt(row.calls)}</Td>
            <Td>{fmt(row.prompt)}</Td>
            <Td>{fmt(row.completion)}</Td>
            <Td className="font-semibold text-ink">{fmt(row.total)}</Td>
            <Td>{fmt(avg)}</Td>
        </>
    );
}

function KindCells({ row, grandTotal, currency }: Readonly<{ row: UsageRow; grandTotal: number; currency: string }>) {
    const share = grandTotal > 0 ? row.total / grandTotal : 0;
    const truncatedRate = row.calls > 0 ? (row.truncated_calls / row.calls) * 100 : 0;
    const latencyLabel =
        row.avg_latency_ms === null
            ? '—'
            : `${fmt(Math.round(row.avg_latency_ms / 1000))} / ${fmt(Math.round((row.max_latency_ms ?? row.avg_latency_ms) / 1000))} dtk`;

    return (
        <>
            <td className="px-5 py-3 font-medium text-ink">
                <div>{row.kind}</div>
                <ProgressBar
                    value={share}
                    size="sm"
                    ariaLabel={`${(share * 100).toFixed(1)}% dari total`}
                    className="mt-1 max-w-[160px]"
                />
            </td>
            <Td>{fmt(row.calls)}</Td>
            <Td>{fmt(row.prompt)}</Td>
            <Td>{fmt(row.completion)}</Td>
            <Td className="font-semibold text-ink">{fmt(row.total)}</Td>
            <Td className="font-semibold text-ink">{formatCost(row.cost, currency)}</Td>
            <Td>{latencyLabel}</Td>
            <td className={cn('px-5 py-3 font-medium', truncatedRate > 1 ? 'text-mood-lemes' : 'text-ink-2')}>
                {row.truncated_calls > 0 ? `${row.truncated_calls} (${truncatedRate.toFixed(1)}%)` : '—'}
            </td>
        </>
    );
}

/* ─── Form bits ───────────────────────────────────────────────────── */

function DateField({
    id,
    label,
    value,
    onChange,
}: Readonly<{ id: string; label: string; value: string; onChange: (v: string) => void }>) {
    return (
        <label htmlFor={id} className="flex flex-col gap-1 font-mono text-xs font-bold uppercase tracking-wider text-ink-2">
            {label}
            <input
                id={id}
                type="date"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="rounded-xl border border-line bg-surface-sunken px-3 py-2 text-sm font-medium text-ink focus:border-leaf focus:outline-none"
            />
        </label>
    );
}

function PresetButton({ label, href, active }: Readonly<{ label: string; href: string; active: boolean }>) {
    return (
        <Link href={href} preserveScroll className={cn(toggleButtonVariants({ size: 'sm', selected: active }))}>
            {label}
        </Link>
    );
}

/**
 * Small "vs periode sebelumnya" delta next to a KPI. Hidden when there is no
 * comparable prior window (range=all) or the prior window had no data.
 */
function DeltaChip({ current, previous }: Readonly<{ current: number; previous: number | null }>) {
    if (previous === null) {
        return null;
    }
    if (previous <= 0) {
        return current > 0 ? <span className="ml-1.5 text-ink-3">· baru</span> : null;
    }

    const pct = Math.round(((current - previous) / previous) * 100);
    let arrow = '·';
    if (pct > 0) {
        arrow = '▲';
    } else if (pct < 0) {
        arrow = '▼';
    }

    return (
        <span className="ml-1.5 text-ink-3">
            {arrow} {Math.abs(pct)}% vs sblm
        </span>
    );
}

function EmptyState() {
    return (
        <Card tone="empty" padding="lg" className="mt-4 text-center">
            <Icon icon="mdi:database-off" width={32} className="mx-auto text-ink-3" aria-hidden />
            <p className="mt-2 text-sm text-ink-2">Belum ada catatan token di rentang ini.</p>
        </Card>
    );
}
