import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import AppShell from '@/layouts/AppShell';
import Paginator from '@/components/Paginator';
import RunListRow from '@/components/run/RunListRow';
import type { Activity, ActivityDetail, PaginatedResponse } from '@/types/inertia';

interface RunsIndexProps {
    runs: PaginatedResponse<Activity & { detail: ActivityDetail }>;
}

export default function RunsIndex({ runs }: Readonly<RunsIndexProps>) {
    return (
        <AppShell>
            <Head title="Aktivitas" />
            <main className="mx-auto max-w-6xl px-6 py-10">
                <header className="mb-6 flex items-end justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight text-ink dark:text-ink-dark">Aktivitas</h1>
                        <p className="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">Semua lari kamu, terbaru di atas.</p>
                    </div>
                </header>

                {runs.data.length === 0 ? (
                    <div className="rounded-2xl border border-dashed border-line bg-surface-elev/40 p-10 text-center dark:border-line-dark dark:bg-surface-dark-elev/40">
                        <Icon icon="mdi:run-fast" width={28} height={28} className="mx-auto text-brand-500" aria-hidden />
                        <p className="mt-3 text-sm text-ink-soft dark:text-ink-soft-dark">Belum ada aktivitas yang dianalisis</p>
                    </div>
                ) : (
                    <>
                        <div className="overflow-hidden rounded-2xl border border-line bg-surface-elev dark:border-line-dark dark:bg-surface-dark-elev">
                            {runs.data.map((activity) =>
                                activity.detail ? <RunListRow key={activity.id} detail={activity.detail} /> : null,
                            )}
                        </div>

                        {runs.last_page > 1 && <Paginator links={runs.links} />}
                    </>
                )}
            </main>
        </AppShell>
    );
}
