import { Head, Link } from '@inertiajs/react';
import { cn } from '@/lib/cn';
import AppShell from '@/layouts/AppShell';
import Paginator from '@/components/Paginator';
import RunCard from '@/components/card/RunCard';
import { RARITY_LABELS, RARITY_ORDER } from '@/lib/runcard';
import type { PaginatedResponse, RunCard as RunCardModel, Activity, ActivityDetail } from '@/types/inertia';

type CardWithRel = RunCardModel & {
    activity: Activity & { detail: ActivityDetail };
};

interface CardsIndexProps {
    cards: PaginatedResponse<CardWithRel>;
    selectedRarity: string | null;
}

export default function CardsIndex({ cards, selectedRarity }: Readonly<CardsIndexProps>) {
    return (
        <AppShell>
            <Head title="Kartu Aktivitas" />
            <main className="mx-auto max-w-6xl px-6 py-10">
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold tracking-tight text-ink dark:text-ink-dark">Kartu Aktivitas</h1>
                    <p className="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">
                        Setiap aktivitas dapat satu kartu. Rarity naik untuk PR, negative split, atau aktivitas terjauh.
                    </p>
                </div>

                <nav className="mb-6 flex flex-wrap gap-2 text-sm">
                    <RarityPill href="/cards" label="Semua" active={selectedRarity === null} />
                    {RARITY_ORDER.map((r) => (
                        <RarityPill
                            key={r}
                            href={`/cards?rarity=${r}`}
                            label={RARITY_LABELS[r]}
                            active={selectedRarity === r}
                        />
                    ))}
                </nav>

                {cards.data.length === 0 ? (
                    <div className="rounded-2xl border border-dashed border-line bg-surface-elev/40 p-10 text-center dark:border-line-dark dark:bg-surface-dark-elev/40">
                        <p className="text-sm text-ink-soft dark:text-ink-soft-dark">Belum ada kartu di rarity ini.</p>
                    </div>
                ) : (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {cards.data.map((card) =>
                                card.activity?.detail ? (
                                    <Link
                                        key={card.id}
                                        href={`/runs/${card.activity_id}`}
                                        className="block h-full transition hover:-translate-y-0.5"
                                    >
                                        <RunCard card={card} detail={card.activity.detail} />
                                    </Link>
                                ) : null,
                            )}
                        </div>
                        {cards.last_page > 1 && <Paginator links={cards.links} />}
                    </>
                )}
            </main>
        </AppShell>
    );
}

function RarityPill({ href, label, active }: Readonly<{ href: string; label: string; active: boolean }>) {
    return (
        <Link
            href={href}
            className={cn(
                'rounded-full border border-line px-3 py-1 text-ink dark:border-line-dark dark:text-ink-dark',
                active && 'bg-brand-500/15 text-brand-700 dark:text-brand-300',
            )}
        >
            {label}
        </Link>
    );
}
