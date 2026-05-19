import { Head, router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useState } from 'react';
import { Icon } from '@iconify/react';
import SectionHeading from '@/components/SectionHeading';
import KpiTile from '@/components/dashboard/KpiTile';
import { fadeInUp } from '@/lib/motion';

interface UsageRow {
    kind: string;
    prompt: number;
    completion: number;
    total: number;
    calls: number;
}

interface UsageTotals {
    prompt: number;
    completion: number;
    total: number;
    calls: number;
}

interface AiUsageProps {
    from: string;
    to: string;
    totals: UsageTotals;
    byKind: UsageRow[];
}

const COLUMNS = ['Jenis', 'Panggilan', 'Prompt', 'Completion', 'Total', 'Rata-rata'] as const;

const numberFmt = new Intl.NumberFormat('id-ID');

function fmt(n: number): string {
    return numberFmt.format(n);
}

function navigate(from: string, to: string): void {
    router.get('/ai-usage', { from, to }, { preserveState: true, preserveScroll: true });
}

function todayISO(): string {
    return new Date().toISOString().slice(0, 10);
}

function isoDaysAgo(days: number): string {
    const d = new Date();
    d.setDate(d.getDate() - days);
    return d.toISOString().slice(0, 10);
}

function isoStartOfMonth(): string {
    const d = new Date();
    return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10);
}

export default function AiUsage({ from, to, totals, byKind }: Readonly<AiUsageProps>) {
    const [fromInput, setFromInput] = useState<string>(from);
    const [toInput, setToInput] = useState<string>(to);

    const applyPreset = (nextFrom: string, nextTo: string): void => {
        setFromInput(nextFrom);
        setToInput(nextTo);
        navigate(nextFrom, nextTo);
    };

    const promptShare = totals.total > 0 ? Math.round((totals.prompt / totals.total) * 100) : 0;
    const avgPerCall = totals.calls > 0 ? Math.round(totals.total / totals.calls) : 0;

    return (
        <div className="min-h-screen bg-surface text-ink">
            <Head title="AI Usage" />

            <header className="border-b border-line bg-surface-elev">
                <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                    <div className="flex items-center gap-3">
                        <span className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-brand-700 text-white">
                            <Icon icon="mdi:counter" width={20} aria-hidden />
                        </span>
                        <div>
                            <h1 className="text-base font-semibold tracking-tight text-ink">AI Usage</h1>
                            <p className="text-xs text-ink-meta">Konsumsi token Azure OpenAI per rentang tanggal.</p>
                        </div>
                    </div>
                    <span className="hidden text-[10px] font-semibold uppercase tracking-wider text-ink-meta sm:inline">
                        TemanLari · Devtools
                    </span>
                </div>
            </header>

            <motion.main
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="mx-auto max-w-6xl px-6 py-8"
            >
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        navigate(fromInput, toInput);
                    }}
                    className="flex flex-wrap items-end gap-3 rounded-2xl border border-line bg-surface-elev p-4 shadow-sm"
                >
                    <DateField id="from" label="Dari" value={fromInput} onChange={setFromInput} />
                    <DateField id="to" label="Sampai" value={toInput} onChange={setToInput} />
                    <button
                        type="submit"
                        className="inline-flex items-center gap-1 rounded-full bg-brand-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-800"
                    >
                        <Icon icon="mdi:filter-variant" aria-hidden />
                        <span>Terapkan</span>
                    </button>

                    <div className="ml-auto flex flex-wrap gap-2">
                        <PresetButton
                            label="Hari ini"
                            onClick={() => applyPreset(todayISO(), todayISO())}
                        />
                        <PresetButton
                            label="7 hari"
                            onClick={() => applyPreset(isoDaysAgo(6), todayISO())}
                        />
                        <PresetButton
                            label="30 hari"
                            onClick={() => applyPreset(isoDaysAgo(29), todayISO())}
                        />
                        <PresetButton
                            label="Bulan ini"
                            onClick={() => applyPreset(isoStartOfMonth(), todayISO())}
                        />
                    </div>
                </form>

                <p className="mt-3 text-xs text-ink-meta">
                    Rentang aktif: <span className="font-semibold text-ink">{from}</span> sampai{' '}
                    <span className="font-semibold text-ink">{to}</span>
                </p>

                <section className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <KpiTile label="Total Tokens" value={fmt(totals.total)} sub={`${totals.calls} call`} />
                    <KpiTile label="Prompt" value={fmt(totals.prompt)} sub={`${promptShare}% dari total`} />
                    <KpiTile label="Completion" value={fmt(totals.completion)} sub={`${100 - promptShare}% dari total`} />
                    <KpiTile label="Avg / Call" value={fmt(avgPerCall)} sub="token" />
                </section>

                <section className="mt-10">
                    <SectionHeading
                        icon="mdi:chart-bar"
                        title="Breakdown per Kind"
                        subtitle="Jenis analisis yang paling banyak makan token."
                        tone="brand"
                    />

                    {byKind.length === 0 ? (
                        <EmptyState />
                    ) : (
                        <div className="mt-4 overflow-x-auto rounded-2xl border border-line bg-surface-elev shadow-sm">
                            <table className="w-full text-sm tabular-nums">
                                <thead>
                                    <tr className="border-b border-line text-left text-xs text-ink-meta">
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
            </motion.main>
        </div>
    );
}

function DateField({
    id,
    label,
    value,
    onChange,
}: Readonly<{ id: string; label: string; value: string; onChange: (v: string) => void }>) {
    return (
        <label htmlFor={id} className="flex flex-col gap-1 text-xs font-semibold uppercase tracking-wider text-ink-meta">
            {label}
            <input
                id={id}
                type="date"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="rounded-xl border border-line bg-surface-sunken px-3 py-2 text-sm font-medium text-ink focus:border-brand-500 focus:outline-none"
            />
        </label>
    );
}

function PresetButton({ label, onClick }: Readonly<{ label: string; onClick: () => void }>) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="rounded-full border border-line bg-surface-sunken px-3 py-1.5 text-xs font-medium text-ink-soft transition hover:border-brand-300 hover:text-brand-700"
        >
            {label}
        </button>
    );
}

function KindRow({ row, grandTotal }: Readonly<{ row: UsageRow; grandTotal: number }>) {
    const share = grandTotal > 0 ? (row.total / grandTotal) * 100 : 0;
    const avg = row.calls > 0 ? Math.round(row.total / row.calls) : 0;

    return (
        <tr className="border-b border-line last:border-b-0">
            <td className="px-5 py-3 font-medium text-ink">
                <div>{row.kind}</div>
                <div className="mt-1 h-1.5 w-full max-w-[160px] rounded-full bg-line/40">
                    <div
                        className="h-full rounded-full bg-brand-500"
                        style={{ width: `${share.toFixed(1)}%` }}
                        aria-label={`${share.toFixed(1)}% dari total`}
                    />
                </div>
            </td>
            <td className="px-5 py-3 text-ink-soft">{fmt(row.calls)}</td>
            <td className="px-5 py-3 text-ink-soft">{fmt(row.prompt)}</td>
            <td className="px-5 py-3 text-ink-soft">{fmt(row.completion)}</td>
            <td className="px-5 py-3 font-semibold text-ink">{fmt(row.total)}</td>
            <td className="px-5 py-3 text-ink-soft">{fmt(avg)}</td>
        </tr>
    );
}

function EmptyState() {
    return (
        <div className="mt-4 rounded-2xl border border-dashed border-line bg-surface-sunken px-6 py-12 text-center">
            <Icon icon="mdi:database-off" width={32} className="mx-auto text-ink-meta" aria-hidden />
            <p className="mt-2 text-sm text-ink-soft">Belum ada token tercatat di rentang ini.</p>
        </div>
    );
}
