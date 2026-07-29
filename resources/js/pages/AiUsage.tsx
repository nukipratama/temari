import { Head, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import AttentionArea from '@/components/aiusage/AttentionArea';
import BudgetGauge from '@/components/aiusage/BudgetGauge';
import DailyChart from '@/components/aiusage/DailyChart';
import DeploymentTable from '@/components/aiusage/DeploymentTable';
import FlashBanner from '@/components/aiusage/FlashBanner';
import KindTable from '@/components/aiusage/KindTable';
import UsageFilters from '@/components/aiusage/UsageFilters';
import UsageKpis from '@/components/aiusage/UsageKpis';
import UserTable from '@/components/aiusage/UserTable';
import SectionHeading from '@/components/SectionHeading';
import PageContainer from '@/components/ui/PageContainer';
import type { SharedProps } from '@/types/inertia';
import type { AiUsageProps } from './AiUsage/types';

export default function AiUsage({
    range,
    from,
    to,
    kind,
    totals,
    previousTotals,
    byKind,
    byUser,
    byDeployment,
    daily,
    availableKinds,
    budget,
    deadLettered,
    failedUnderBudget,
    nyangkut,
}: Readonly<AiUsageProps>) {
    const flashInfo = usePage<SharedProps>().props.flash?.info;
    const currency = budget.currency;

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
                        Temari · Devtools
                    </span>
                </div>
            </header>

            <PageContainer>
                {flashInfo && <FlashBanner message={flashInfo} />}

                <UsageFilters range={range} from={from} to={to} kind={kind} availableKinds={availableKinds} />

                <UsageKpis totals={totals} previousTotals={previousTotals} currency={currency} />

                <BudgetGauge budget={budget} />

                <AttentionArea deadLettered={deadLettered} failedUnderBudget={failedUnderBudget} nyangkut={nyangkut} />

                {daily.length > 0 && (
                    <section className="mt-10">
                        <SectionHeading
                            icon="mdi:chart-bar"
                            title="Konsumsi Harian"
                            subtitle="Token per hari dalam rentang yang dipilih."
                            tone="accent"
                        />

                        <DailyChart data={daily} currency={currency} />
                    </section>
                )}

                <DeploymentTable rows={byDeployment} currency={currency} />

                <KindTable rows={byKind} grandTotal={totals.total} currency={currency} />

                <UserTable rows={byUser} grandTotal={totals.total} />
            </PageContainer>
        </div>
    );
}
