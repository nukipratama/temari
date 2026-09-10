import { Head, usePage } from '@inertiajs/react';
import { Hash } from 'lucide-react';
import { useState } from 'react';

import type { SharedProps } from '@/types/inertia';

import AthleteTable from '@/components/narration/AthleteTable';
import CeilingHeader from '@/components/narration/CeilingHeader';
import CostChart from '@/components/narration/CostChart';
import DeploymentTable from '@/components/narration/DeploymentTable';
import FlashBanner from '@/components/narration/FlashBanner';
import KindTable from '@/components/narration/KindTable';
import OriginTable from '@/components/narration/OriginTable';
import UsageFilters from '@/components/narration/UsageFilters';
import UsageKpis from '@/components/narration/UsageKpis';
import { Icon } from '@/components/ui/Icon';
import PageContainer from '@/components/ui/PageContainer';
import { cn } from '@/lib/cn';
import { toggleButtonVariants } from '@/lib/variants';
import { navigate } from '@/pages/Narration/helpers';

import type { NarrationOverviewProps } from './types';

type Tab = 'overview' | 'breakdown';

export default function Overview({
    range,
    from,
    to,
    kind,
    origin,
    athlete,
    totals,
    previousTotals,
    byKind,
    byDeployment,
    byOrigin,
    availableKinds,
    availableOrigins,
    budget,
    contentFilter,
    chart,
    athletes,
    cappedToday,
    pauseReason,
}: Readonly<NarrationOverviewProps>) {
    const flashInfo = usePage<SharedProps>().props.flash?.info;
    const [tab, setTab] = useState<Tab>('overview');
    const currency = budget.currency;

    return (
        <div className="min-h-screen bg-background text-foreground">
            <Head title="Narration" />

            <header className="border-b border-border bg-popover">
                <div className="mx-auto flex max-w-page items-center justify-between px-6 py-4 2xl:max-w-page-2xl">
                    <div className="flex items-center gap-3">
                        <span className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-leaf-deep text-cream">
                            <Icon icon={Hash} width={20} aria-hidden />
                        </span>
                        <div>
                            <h1 className="font-serif italic text-headline-xs text-foreground">
                                narration
                            </h1>
                            <p className="text-xs text-text-3">
                                What the narration pipeline costs, per athlete.
                            </p>
                        </div>
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
                {flashInfo && <FlashBanner message={flashInfo} />}

                <UsageFilters
                    range={range}
                    from={from}
                    to={to}
                    kind={kind}
                    origin={origin}
                    athlete={athlete}
                    availableKinds={availableKinds}
                    availableOrigins={availableOrigins}
                />

                <div className="mt-6 flex gap-2" role="tablist">
                    <TabButton
                        label="overview"
                        active={tab === 'overview'}
                        onSelect={() => setTab('overview')}
                    />
                    <TabButton
                        label="breakdown"
                        active={tab === 'breakdown'}
                        onSelect={() => setTab('breakdown')}
                    />
                </div>

                {tab === 'overview' ? (
                    <>
                        <CeilingHeader
                            budget={budget}
                            cappedToday={cappedToday}
                            pauseReason={pauseReason}
                        />

                        <UsageKpis
                            totals={totals}
                            previousTotals={previousTotals}
                            currency={currency}
                            contentFilter={contentFilter}
                        />

                        <CostChart
                            chart={chart}
                            currency={currency}
                            athletes={athletes}
                            selected={athlete}
                            onSelect={(next) =>
                                navigate({
                                    range,
                                    from,
                                    to,
                                    kind,
                                    origin,
                                    athlete: next,
                                })
                            }
                        />

                        <AthleteTable rows={athletes} currency={currency} />
                    </>
                ) : (
                    <>
                        <KindTable
                            rows={byKind}
                            grandTotal={totals.total}
                            currency={currency}
                        />

                        <DeploymentTable
                            rows={byDeployment}
                            currency={currency}
                        />

                        <OriginTable rows={byOrigin} currency={currency} />
                    </>
                )}
            </PageContainer>
        </div>
    );
}

function TabButton({
    label,
    active,
    onSelect,
}: Readonly<{ label: string; active: boolean; onSelect: () => void }>) {
    return (
        <button
            type="button"
            role="tab"
            aria-selected={active}
            onClick={onSelect}
            className={cn(
                toggleButtonVariants({ size: 'sm', selected: active }),
            )}
        >
            {label}
        </button>
    );
}
