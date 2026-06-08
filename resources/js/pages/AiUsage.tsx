import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Icon } from '@iconify/react';
import { formatMonthDayId, formatWeekdayDayId, isoDaysAgoLocal, isoStartOfMonthLocal, todayLocalIso } from '@/lib/pace';
import SectionHeading from '@/components/SectionHeading';
import KpiTile from '@/components/dashboard/KpiTile';
import PageContainer from '@/components/ui/PageContainer';

interface UsageRow {
    kind: string;
    prompt: number;
    completion: number;
    total: number;
    calls: number;
    truncated_calls: number;
    avg_latency_ms: number | null;
    max_latency_ms: number | null;
}

interface UsageTotals {
    prompt: number;
    completion: number;
    total: number;
    calls: number;
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
}

interface KindOption {
    value: string;
    label: string;
}

interface AiUsageProps {
    from: string;
    to: string;
    kind: string | null;
    totals: UsageTotals;
    byKind: UsageRow[];
    byUser: UserRow[];
    daily: DailyRow[];
    availableKinds: KindOption[];
}

const COLUMNS = ['Jenis', 'Panggilan', 'Prompt', 'Completion', 'Total', 'Rata-rata', 'Latency (avg/max)', 'Terpotong'] as const;
const USER_COLUMNS = ['User', 'Panggilan', 'Prompt', 'Completion', 'Total', 'Rata-rata'] as const;

const numberFmt = new Intl.NumberFormat('id-ID');
const costFmt = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function fmt(n: number): string {
    return numberFmt.format(n);
}

/** Cost per 1K tokens (GPT-4o-mini equivalent). */
const COST_PER_1K = 0.01;

function estimateCost(tokens: number): number {
    return (tokens / 1000) * COST_PER_1K;
}

function navigate(from: string, to: string, kind: string | null): void {
    const params: Record<string, string> = { from, to };
    if (kind !== null) {
        params.kind = kind;
    }
    router.get('/ai-usage', params, { preserveState: true, preserveScroll: true });
}

function formatDayLabel(day: string): string {
    return formatMonthDayId(new Date(day + 'T00:00:00'));
}

function formatDayLabelShort(day: string): string {
    return formatWeekdayDayId(new Date(day + 'T00:00:00'));
}

export default function AiUsage({
    from,
    to,
    kind,
    totals,
    byKind,
    byUser,
    daily,
    availableKinds,
}: Readonly<AiUsageProps>) {
    const [fromInput, setFromInput] = useState<string>(from);
    const [toInput, setToInput] = useState<string>(to);
    const [kindInput, setKindInput] = useState<string>(kind ?? '');

    const promptShare = totals.total > 0 ? Math.round((totals.prompt / totals.total) * 100) : 0;
    const avgPerCall = totals.calls > 0 ? Math.round(totals.total / totals.calls) : 0;
    const truncatedShare = totals.calls > 0 ? Math.round((totals.truncated_calls / totals.calls) * 100) : 0;
    const totalCost = estimateCost(totals.total);

    function handleSubmit(e: React.FormEvent): void {
        e.preventDefault();
        navigate(fromInput, toInput, kindInput || null);
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
                <form
                    onSubmit={handleSubmit}
                    className="flex flex-wrap items-end gap-3 rounded-2xl border border-line bg-surface-elev p-4 shadow-sm"
                >
                    <DateField id="from" label="Dari" value={fromInput} onChange={setFromInput} />
                    <DateField id="to" label="Sampai" value={toInput} onChange={setToInput} />

                    {availableKinds.length > 0 && (
                        <KindFilter kinds={availableKinds} value={kindInput} onChange={setKindInput} />
                    )}

                    <button
                        type="submit"
                        className="focus-ring inline-flex items-center gap-1 rounded-full bg-leaf-deep px-4 py-2 text-sm font-semibold text-cream transition hover:opacity-90"
                    >
                        <Icon icon="mdi:filter-variant" aria-hidden />
                        <span>Terapkan</span>
                    </button>

                    <div className="ml-auto flex flex-wrap gap-2">
                        <PresetButton label="Hari ini" href={`/ai-usage?from=${todayLocalIso()}&to=${todayLocalIso()}`} />
                        <PresetButton label="7 hari" href={`/ai-usage?from=${isoDaysAgoLocal(6)}&to=${todayLocalIso()}`} />
                        <PresetButton label="30 hari" href={`/ai-usage?from=${isoDaysAgoLocal(29)}&to=${todayLocalIso()}`} />
                        <PresetButton label="Bulan ini" href={`/ai-usage?from=${isoStartOfMonthLocal()}&to=${todayLocalIso()}`} />
                    </div>
                </form>

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
                    <KpiTile label="Total Tokens" value={fmt(totals.total)} sub={`${totals.calls} call`} />
                    <KpiTile label="Prompt" value={fmt(totals.prompt)} sub={`${promptShare}% dari total`} />
                    <KpiTile label="Completion" value={fmt(totals.completion)} sub={`${100 - promptShare}% dari total`} />
                    <KpiTile
                        label="Terpotong"
                        value={`${truncatedShare}%`}
                        sub={`${totals.truncated_calls} dari ${totals.calls} call`}
                        tone={truncatedShare > 1 ? 'alert' : 'neutral'}
                    />
                </section>

                <p className="mt-2 text-xs text-ink-3">
                    Rata-rata per call: <span className="font-semibold text-ink">{fmt(avgPerCall)}</span> token.
                    {' '}
                    Estimasi biaya total: <span className="font-semibold text-ink">${costFmt.format(totalCost)}</span>
                    {' '}
                    <span className="text-ink-3">(@ ${COST_PER_1K}/1K token)</span>
                </p>

                {daily.length > 0 && (
                    <section className="mt-10">
                        <SectionHeading
                            icon="mdi:chart-bar"
                            title="Konsumsi Harian"
                            subtitle="Token per hari dalam rentang yang dipilih."
                            tone="accent"
                        />

                        <DailyChart data={daily} />
                    </section>
                )}

                <section className="mt-10">
                    <SectionHeading
                        icon="mdi:shape"
                        title="Breakdown per Kind"
                        subtitle="Jenis analisis yang paling banyak makan token."
                        tone="brand"
                    />

                    {byKind.length === 0 ? (
                        <EmptyState />
                    ) : (
                        <div className="mt-4 overflow-x-auto rounded-2xl border border-line bg-surface-elev shadow-sm">
                            <table className="w-full min-w-[680px] text-sm tabular-nums">
                                <thead>
                                    <tr className="border-b border-line text-left text-xs text-ink-3">
                                        {COLUMNS.map((label) => (
                                            <th key={label} className="px-5 py-3 font-semibold">
                                                {label}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {byKind.map((row) => (
                                        <KindRow key={row.kind} row={row} grandTotal={totals.total} />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section className="mt-10">
                    <SectionHeading
                        icon="mdi:account-multiple"
                        title="Breakdown per User"
                        subtitle="Pengguna yang paling banyak mengajak Temari mengobrol."
                        tone="accent"
                    />

                    {byUser.length === 0 ? (
                        <EmptyState />
                    ) : (
                        <div className="mt-4 overflow-x-auto rounded-2xl border border-line bg-surface-elev shadow-sm">
                            <table className="w-full min-w-[520px] text-sm tabular-nums">
                                <thead>
                                    <tr className="border-b border-line text-left text-xs text-ink-3">
                                        {USER_COLUMNS.map((label) => (
                                            <th key={label} className="px-5 py-3 font-semibold">
                                                {label}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {byUser.map((row) => (
                                        <UserRowView key={row.user_id} row={row} grandTotal={totals.total} />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </PageContainer>
        </div>
    );
}

/* ─── Daily Bar Chart ─────────────────────────────────────────────── */

function DailyChart({ data }: Readonly<{ data: DailyRow[] }>) {
    const maxTotal = Math.max(...data.map((d) => d.total), 1);
    const totalTokens = data.reduce((sum, d) => sum + d.total, 0);
    const totalCost = estimateCost(totalTokens);

    return (
        <div className="mt-4 rounded-2xl border border-line bg-surface-elev p-5 shadow-sm">
            <div className="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                <span className="text-label-small text-ink-3">{data.length} hari</span>
                <span className="text-sm text-ink-2">
                    Estimasi biaya: <span className="font-semibold text-ink">${costFmt.format(totalCost)}</span>
                </span>
            </div>

            <div className="flex items-end gap-1.5" style={{ height: 180 }}>
                {data.map((d) => {
                    const heightPct = Math.max((d.total / maxTotal) * 100, 2);
                    const dayCost = estimateCost(d.total);

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
                                <div className="text-ink-3">${costFmt.format(dayCost)}</div>
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
        </div>
    );
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

/* ─── Supporting components (unchanged) ───────────────────────────── */

function UserRowView({ row, grandTotal }: Readonly<{ row: UserRow; grandTotal: number }>) {
    const share = grandTotal > 0 ? (row.total / grandTotal) * 100 : 0;
    const avg = row.calls > 0 ? Math.round(row.total / row.calls) : 0;
    const label = row.user_name ?? `User #${row.user_id}`;

    return (
        <tr className="border-b border-line last:border-b-0">
            <td className="px-5 py-3 font-medium text-ink">
                <div>{label}</div>
                <div className="mt-1 h-1.5 w-full max-w-[160px] rounded-full bg-line/40">
                    <div
                        className="h-full rounded-full bg-horizon"
                        style={{ width: `${share.toFixed(1)}%` }}
                        aria-label={`${share.toFixed(1)}% dari total`}
                    />
                </div>
            </td>
            <td className="px-5 py-3 text-ink-2">{fmt(row.calls)}</td>
            <td className="px-5 py-3 text-ink-2">{fmt(row.prompt)}</td>
            <td className="px-5 py-3 text-ink-2">{fmt(row.completion)}</td>
            <td className="px-5 py-3 font-semibold text-ink">{fmt(row.total)}</td>
            <td className="px-5 py-3 text-ink-2">{fmt(avg)}</td>
        </tr>
    );
}

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

function PresetButton({ label, href }: Readonly<{ label: string; href: string }>) {
    return (
        <Link
            href={href}
            preserveScroll
            className="focus-ring rounded-full border border-line bg-surface-sunken px-3 py-1.5 text-xs font-medium text-ink-2 transition hover:border-leaf/40 hover:text-leaf-deep"
        >
            {label}
        </Link>
    );
}

function KindRow({ row, grandTotal }: Readonly<{ row: UsageRow; grandTotal: number }>) {
    const share = grandTotal > 0 ? (row.total / grandTotal) * 100 : 0;
    const avg = row.calls > 0 ? Math.round(row.total / row.calls) : 0;
    const truncatedRate = row.calls > 0 ? (row.truncated_calls / row.calls) * 100 : 0;
    const latencyLabel =
        row.avg_latency_ms === null
            ? '—'
            : `${fmt(Math.round(row.avg_latency_ms / 1000))} / ${fmt(Math.round((row.max_latency_ms ?? row.avg_latency_ms) / 1000))} dtk`;

    return (
        <tr className="border-b border-line last:border-b-0">
            <td className="px-5 py-3 font-medium text-ink">
                <div>{row.kind}</div>
                <div className="mt-1 h-1.5 w-full max-w-[160px] rounded-full bg-line/40">
                    <div
                        className="h-full rounded-full bg-leaf"
                        style={{ width: `${share.toFixed(1)}%` }}
                        aria-label={`${share.toFixed(1)}% dari total`}
                    />
                </div>
            </td>
            <td className="px-5 py-3 text-ink-2">{fmt(row.calls)}</td>
            <td className="px-5 py-3 text-ink-2">{fmt(row.prompt)}</td>
            <td className="px-5 py-3 text-ink-2">{fmt(row.completion)}</td>
            <td className="px-5 py-3 font-semibold text-ink">{fmt(row.total)}</td>
            <td className="px-5 py-3 text-ink-2">{fmt(avg)}</td>
            <td className="px-5 py-3 text-ink-2">{latencyLabel}</td>
            <td className={`px-5 py-3 font-medium ${truncatedRate > 1 ? 'text-horizon-deep' : 'text-ink-2'}`}>
                {row.truncated_calls > 0 ? `${row.truncated_calls} (${truncatedRate.toFixed(1)}%)` : '—'}
            </td>
        </tr>
    );
}

function EmptyState() {
    return (
        <div className="mt-4 rounded-2xl border border-dashed border-line bg-surface-sunken px-6 py-12 text-center">
            <Icon icon="mdi:database-off" width={32} className="mx-auto text-ink-3" aria-hidden />
            <p className="mt-2 text-sm text-ink-2">Belum ada catatan token di rentang ini.</p>
        </div>
    );
}
