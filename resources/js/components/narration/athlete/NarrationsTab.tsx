import { Link, router } from '@inertiajs/react';

import type { AthletePageProps } from '@/pages/Narration/types';

import EmptyPanel from '@/components/ui/EmptyPanel';

import NarrationRow from './NarrationRow';

type NarrationsTabProps = Pick<
    AthletePageProps,
    | 'narrations'
    | 'nextCursor'
    | 'filters'
    | 'availableKinds'
    | 'availableStatuses'
    | 'replayBudget'
> & { athleteId: number; currency: string };

/** Build a `?tab=narrations&…` href, dropping the cursor when a filter changes. */
export function narrationsHref(
    athleteId: number,
    filters: { kind: string | null; status: string | null },
    before: number | null = null,
): string {
    const params = new URLSearchParams({ tab: 'narrations' });
    if (filters.kind !== null) {
        params.set('kind', filters.kind);
    }
    if (filters.status !== null) {
        params.set('status', filters.status);
    }
    if (before !== null) {
        params.set('before', String(before));
    }
    return `/devtools/narration/athletes/${athleteId}?${params.toString()}`;
}

export default function NarrationsTab({
    narrations,
    nextCursor,
    filters,
    availableKinds,
    availableStatuses,
    replayBudget,
    athleteId,
    currency,
}: Readonly<NarrationsTabProps>) {
    return (
        <section className="mt-6">
            <form className="flex flex-wrap items-end gap-3">
                <Filter
                    id="kind-filter"
                    label="kind"
                    value={filters.kind}
                    options={availableKinds}
                    onChange={(value) =>
                        router.get(
                            narrationsHref(athleteId, {
                                ...filters,
                                kind: value,
                            }),
                        )
                    }
                />
                <Filter
                    id="status-filter"
                    label="status"
                    value={filters.status}
                    options={availableStatuses}
                    onChange={(value) =>
                        router.get(
                            narrationsHref(athleteId, {
                                ...filters,
                                status: value,
                            }),
                        )
                    }
                />
            </form>

            {narrations.length === 0 ? (
                <EmptyPanel
                    title="no narrations here"
                    body="nothing matches these filters for this athlete."
                    className="mt-4"
                />
            ) : (
                <ul className="mt-4 flex flex-col gap-3">
                    {narrations.map((row) => (
                        <NarrationRow
                            key={row.id}
                            row={row}
                            currency={currency}
                            athleteId={athleteId}
                            replayBudget={replayBudget}
                        />
                    ))}
                </ul>
            )}

            {nextCursor !== null && (
                <Link
                    href={narrationsHref(athleteId, filters, nextCursor)}
                    className="focus-ring mt-4 inline-block rounded-full text-sm font-semibold text-leaf-ink"
                >
                    older
                </Link>
            )}
        </section>
    );
}

function Filter({
    id,
    label,
    value,
    options,
    onChange,
}: Readonly<{
    id: string;
    label: string;
    value: string | null;
    options: string[];
    onChange: (value: string | null) => void;
}>) {
    return (
        <label
            htmlFor={id}
            className="flex flex-col gap-1 font-mono text-xs font-bold uppercase tracking-wider text-text-2"
        >
            {label}
            <select
                id={id}
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value || null)}
                className="focus-ring rounded-xl border border-border bg-muted px-3 py-2 text-sm font-medium text-foreground focus:border-leaf"
            >
                <option value="">all</option>
                {options.map((option) => (
                    <option key={option} value={option}>
                        {option}
                    </option>
                ))}
            </select>
        </label>
    );
}
