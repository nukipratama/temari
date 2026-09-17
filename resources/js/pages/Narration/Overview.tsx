import { Head, usePage } from '@inertiajs/react';
import { Hash } from 'lucide-react';
import { useState } from 'react';

import type { SharedProps } from '@/types/inertia';

import AthletesPanel from '@/components/narration/AthletesPanel';
import CostChart from '@/components/narration/CostChart';
import DeploymentTable from '@/components/narration/DeploymentTable';
import FaultStrip from '@/components/narration/FaultStrip';
import FlashBanner from '@/components/narration/FlashBanner';
import KindTable from '@/components/narration/KindTable';
import NarratorRanking from '@/components/narration/NarratorRanking';
import OriginTable from '@/components/narration/OriginTable';
import RuleBasedPanel from '@/components/narration/RuleBasedPanel';
import TodayPanel from '@/components/narration/TodayPanel';
import UsageFilters from '@/components/narration/UsageFilters';
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

            <PageContainer className="min-[900px]:max-w-page min-[1280px]:max-w-page 2xl:max-w-page-2xl">
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
                        <div className="mt-6">
                            <FaultStrip
                                pauseReason={pauseReason}
                                budget={budget}
                                cappedToday={cappedToday}
                                athletes={athletes}
                                contentFilter={contentFilter}
                            />
                        </div>

                        <TodayPanel budget={budget} chart={chart} />

                        <section className="mt-10 grid items-start gap-5 lg:grid-cols-[1.35fr_1fr]">
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
                            <NarratorRanking
                                chart={chart}
                                byKind={byKind}
                                currency={currency}
                            />
                        </section>

                        <AthletesPanel rows={athletes} currency={currency} />

                        <RuleBasedPanel athletes={athletes} />

                        <p className="mt-6 text-xs text-text-3">
                            quality signals — flags, per-deployment and
                            per-origin cost — live on the breakdown tab.
                        </p>
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
