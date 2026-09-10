import { Head, Link, usePage } from '@inertiajs/react';

import type { SharedProps } from '@/types/inertia';

import AthleteHeader from '@/components/narration/athlete/AthleteHeader';
import AttentionTab from '@/components/narration/athlete/AttentionTab';
import CostByKindTab from '@/components/narration/athlete/CostByKindTab';
import FlashNotice from '@/components/narration/athlete/FlashNotice';
import NarrationsTab from '@/components/narration/athlete/NarrationsTab';
import PageContainer from '@/components/ui/PageContainer';
import { cn } from '@/lib/cn';
import { toggleButtonVariants } from '@/lib/variants';

import type { AthletePageProps, AthleteTab } from './types';

const TABS: ReadonlyArray<{ token: AthleteTab; label: string }> = [
    { token: 'narrations', label: 'narrations' },
    { token: 'cost', label: 'cost by kind' },
    { token: 'attention', label: 'attention' },
];

export default function Athlete({
    tab,
    filters,
    availableKinds,
    availableStatuses,
    header,
    narrations,
    nextCursor,
    costByKind,
    attention,
    audit,
    override,
    replayBudget,
}: Readonly<AthletePageProps>) {
    const flashInfo = usePage<SharedProps>().props.flash?.info;
    const athleteId = header.athlete.id;
    const currency = header.currency;

    return (
        <div className="min-h-screen bg-background text-foreground">
            <Head title={`${header.athlete.name} · narration`} />

            <header className="border-b border-border bg-popover">
                <div className="mx-auto flex max-w-page items-center justify-between px-6 py-4 2xl:max-w-page-2xl">
                    <div>
                        <h1 className="font-serif italic text-headline-xs text-foreground">
                            {header.athlete.name}
                        </h1>
                        <p className="text-xs text-text-3">
                            narration, spend and stuck work for one athlete
                            {header.athlete.is_demo ? ' · demo account' : ''}
                        </p>
                    </div>
                    <a
                        href="/devtools"
                        className="focus-ring hidden rounded-full px-2 py-1 text-label-micro font-semibold text-text-3 transition hover:text-foreground sm:inline"
                    >
                        Temari · Devtools
                    </a>
                </div>
            </header>

            <PageContainer>
                {flashInfo && <FlashNotice message={flashInfo} />}

                <AthleteHeader header={header} />

                <nav className="mt-8 flex flex-wrap gap-2">
                    {TABS.map((entry) => (
                        <Link
                            key={entry.token}
                            href={`/devtools/narration/athletes/${athleteId}?tab=${entry.token}`}
                            preserveScroll
                            className={cn(
                                toggleButtonVariants({
                                    size: 'sm',
                                    selected: tab === entry.token,
                                }),
                            )}
                        >
                            {entry.label}
                        </Link>
                    ))}
                </nav>

                {tab === 'narrations' && (
                    <NarrationsTab
                        narrations={narrations}
                        nextCursor={nextCursor}
                        filters={filters}
                        availableKinds={availableKinds}
                        availableStatuses={availableStatuses}
                        replayBudget={replayBudget}
                        athleteId={athleteId}
                        currency={currency}
                    />
                )}

                {tab === 'cost' && (
                    <CostByKindTab rows={costByKind} currency={currency} />
                )}

                {tab === 'attention' && (
                    <AttentionTab
                        athleteId={athleteId}
                        currency={currency}
                        failed={attention.failed}
                        deadLettered={attention.dead_lettered}
                        stuck={attention.stuck}
                        audit={audit}
                        override={override}
                    />
                )}
            </PageContainer>
        </div>
    );
}
