import { Head, Link, usePage } from '@inertiajs/react';

import type { SharedProps } from '@/types/inertia';

import AthleteHeader from '@/components/narration/athlete/AthleteHeader';
import AttentionTab from '@/components/narration/athlete/AttentionTab';
import CostByKindTab from '@/components/narration/athlete/CostByKindTab';
import NarrationsTab from '@/components/narration/athlete/NarrationsTab';
import DevtoolsHeader from '@/components/narration/DevtoolsHeader';
import FlashBanner from '@/components/narration/FlashBanner';
import LastOpen from '@/components/narration/LastOpen';
import PageContainer from '@/components/ui/PageContainer';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

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

            <DevtoolsHeader title={header.athlete.name}>
                <p className="text-xs text-text-3">
                    narration, spend and stuck work for one athlete
                    {header.athlete.is_demo ? ' · demo account' : ''}
                    {header.athlete.strava_athlete_id !== null
                        ? ` · Strava ${header.athlete.strava_athlete_id}`
                        : ''}
                </p>
                <div className="mt-1">
                    <LastOpen
                        lastSeenAt={header.athlete.last_seen_at}
                        away={header.athlete.away}
                        isDemo={header.athlete.is_demo}
                    />
                </div>
            </DevtoolsHeader>

            <PageContainer className="min-[900px]:max-w-page min-[1280px]:max-w-page 2xl:max-w-page-2xl">
                {flashInfo && <FlashBanner message={flashInfo} />}

                <AthleteHeader header={header} />

                <ToggleGroup
                    value={tab}
                    aria-label="athlete view"
                    className="mt-8"
                >
                    {TABS.map((entry) => (
                        <ToggleGroupItem
                            key={entry.token}
                            value={entry.token}
                            nativeButton={false}
                            render={
                                <Link
                                    href={`/devtools/narration/athletes/${athleteId}?tab=${entry.token}`}
                                    preserveScroll
                                />
                            }
                        >
                            {entry.label}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>

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
