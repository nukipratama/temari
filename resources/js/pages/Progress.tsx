import { Head, Link } from '@inertiajs/react';
import { cn } from '@/lib/cn';
import { formatDurationHMS, formatIdDate } from '@/lib/pace';
import AppShell from '@/layouts/AppShell';
import type { FormStatus, PersonalRecord, WeeklySnapshot } from '@/types/inertia';

interface ExtendedSnapshot extends WeeklySnapshot {
    weekly_trimp: number | null;
    form_status: FormStatus | null;
}

interface ExtendedPR extends PersonalRecord {
    value_sec: number;
    set_at: string;
    activity?: { detail?: { name?: string | null } | null };
}

interface ProgressProps {
    snapshots: ExtendedSnapshot[];
    personalRecords: ExtendedPR[];
}

const DISTANCE_CATEGORIES = new Set(['1km', '5km', '10km', '15km', 'half_marathon', 'marathon']);

export default function Progress({ snapshots, personalRecords }: Readonly<ProgressProps>) {
    return (
        <AppShell>
            <Head title="Catatan" />
            <main className="mx-auto max-w-5xl px-6 py-10">
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold tracking-tight text-ink dark:text-ink-dark">Catatan</h1>
                    <p className="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">Tren mingguan + ledger PR.</p>
                </div>

                {snapshots.length > 0 && (
                    <section className="mb-10">
                        <h2 className="mb-3 text-xs font-semibold uppercase tracking-wider text-ink-soft dark:text-ink-soft-dark">
                            Riwayat Mingguan
                        </h2>
                        <div className="overflow-x-auto rounded-2xl border border-line bg-surface-elev dark:border-line-dark dark:bg-surface-dark-elev">
                            <table className="w-full text-sm tabular-nums">
                                <thead>
                                    <tr className="border-b border-line text-left text-xs text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">
                                        <th className="px-5 py-3 font-semibold">Week ending</th>
                                        <th className="px-5 py-3 font-semibold">Volume</th>
                                        <th className="px-5 py-3 font-semibold">Aktivitas</th>
                                        <th className="px-5 py-3 font-semibold">TRIMP</th>
                                        <th className="px-5 py-3 font-semibold">CTL</th>
                                        <th className="px-5 py-3 font-semibold">ATL</th>
                                        <th className="px-5 py-3 font-semibold">Form</th>
                                        <th className="px-5 py-3 font-semibold">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {snapshots.map((snap) => (
                                        <tr key={snap.id} className="border-b border-line last:border-b-0 dark:border-line-dark">
                                            <td className="px-5 py-2.5 font-medium text-ink dark:text-ink-dark">
                                                {formatIdDate(snap.week_ending, 'long')}
                                            </td>
                                            <td className="px-5 py-2.5 text-ink dark:text-ink-dark">
                                                {snap.distance_km != null ? `${snap.distance_km.toFixed(1)} km` : '—'}
                                            </td>
                                            <td className="px-5 py-2.5 text-ink dark:text-ink-dark">{snap.runs ?? '—'}</td>
                                            <td className="px-5 py-2.5 text-ink dark:text-ink-dark">
                                                {snap.weekly_trimp != null ? Math.round(snap.weekly_trimp) : '—'}
                                            </td>
                                            <td className="px-5 py-2.5 text-ink dark:text-ink-dark">
                                                {snap.ctl_42d != null ? snap.ctl_42d.toFixed(1) : '—'}
                                            </td>
                                            <td className="px-5 py-2.5 text-ink dark:text-ink-dark">
                                                {snap.atl_7d != null ? snap.atl_7d.toFixed(1) : '—'}
                                            </td>
                                            <td className="px-5 py-2.5 text-ink dark:text-ink-dark">
                                                {snap.form != null ? snap.form.toFixed(1) : '—'}
                                            </td>
                                            <td className={cn('px-5 py-2.5 capitalize', formStatusTone(snap.form_status))}>
                                                {snap.form_status ?? '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                <section>
                    <h2 className="mb-3 text-xs font-semibold uppercase tracking-wider text-ink-soft dark:text-ink-soft-dark">
                        Personal Records
                    </h2>
                    {personalRecords.length === 0 ? (
                        <div className="rounded-2xl border border-dashed border-line bg-surface-elev/40 p-10 text-center dark:border-line-dark dark:bg-surface-dark-elev/40">
                            <p className="text-sm text-ink-soft dark:text-ink-soft-dark">
                                Belum ada PR. Run yang dianalisis dengan splits + best-effort paces akan mengisi ledger di sini.
                            </p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto rounded-2xl border border-line bg-surface-elev dark:border-line-dark dark:bg-surface-dark-elev">
                            <table className="w-full text-sm tabular-nums">
                                <thead>
                                    <tr className="border-b border-line text-left text-xs text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">
                                        <th className="px-5 py-3 font-semibold">Category</th>
                                        <th className="px-5 py-3 font-semibold">Value</th>
                                        <th className="px-5 py-3 font-semibold">Activity</th>
                                        <th className="px-5 py-3 text-right font-semibold">Set on</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {personalRecords.map((pr) => (
                                        <tr key={pr.id} className="border-b border-line last:border-b-0 dark:border-line-dark">
                                            <td className="px-5 py-2.5 font-medium text-ink dark:text-ink-dark">{pr.category}</td>
                                            <td className="px-5 py-2.5 font-bold text-ink dark:text-ink-dark">
                                                {formatPrValue(pr.category, pr.value_sec)}
                                            </td>
                                            <td className="px-5 py-2.5">
                                                {pr.activity_id !== null ? (
                                                    <Link
                                                        href={`/runs/${pr.activity_id}`}
                                                        className="text-brand-600 transition hover:underline dark:text-brand-400"
                                                    >
                                                        {pr.activity?.detail?.name ?? 'Run'}
                                                    </Link>
                                                ) : (
                                                    <span className="text-ink-soft dark:text-ink-soft-dark">—</span>
                                                )}
                                            </td>
                                            <td className="px-5 py-2.5 text-right text-xs text-ink-soft dark:text-ink-soft-dark">
                                                {formatIdDate(pr.set_at, 'long')}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </main>
        </AppShell>
    );
}

function formStatusTone(status: string | null): string {
    switch (status) {
        case 'fresh':
            return 'text-mood-bouncy';
        case 'fatigued':
            return 'text-mood-glow';
        case 'overreaching':
            return 'text-mood-cooked';
        default:
            return 'text-ink dark:text-ink-dark';
    }
}

function formatPrValue(category: string, secs: number): string {
    if (DISTANCE_CATEGORIES.has(category)) {
        return formatDurationHMS(secs);
    }
    return `${Math.floor(secs / 60)}:${(secs % 60).toString().padStart(2, '0')}/km`;
}
