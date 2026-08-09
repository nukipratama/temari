import { Icon } from '@iconify/react';
import { Head } from '@inertiajs/react';

import type { Rarity } from '@/types/inertia';

import CollectionHeader from '@/components/koleksi/CollectionHeader';
import Card from '@/components/ui/Card';
import Eyebrow from '@/components/ui/Eyebrow';
import PageContainer from '@/components/ui/PageContainer';
import ProgressBar from '@/components/ui/ProgressBar';
import SectionLabel from '@/components/ui/SectionLabel';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import { formatGoalNumber, goalProgressRatio } from '@/lib/goalProgress';
import { RARITY_TEXT } from '@/lib/runcard';

interface Goal {
    id: string;
    title: string;
    description: string;
    slot: string;
    rarity: Rarity;
    current: number;
    target: number;
    unit: string;
    is_completed: boolean;
}

interface TargetProps {
    goals: Goal[];
    completedCount: number;
    totalCount: number;
}

const SLOT_LABEL: Record<string, string> = {
    medal: 'Medal',
    ikat_kepala: 'Headband',
    kaus: 'Shirt',
    celana: 'Shorts',
    sepatu: 'Shoes',
    aura: 'Aura',
};

const SLOT_ORDER = [
    'medal',
    'ikat_kepala',
    'kaus',
    'celana',
    'sepatu',
    'aura',
] as const;

const SLOT_ICONS: Record<string, string> = {
    medal: 'mdi:medal',
    ikat_kepala: 'mdi:bandage',
    kaus: 'mdi:tshirt-crew',
    celana: 'mdi:lingerie',
    sepatu: 'mdi:shoe-sneaker',
    aura: 'mdi:blur',
};

export default function Target({
    goals,
    completedCount,
    totalCount,
}: Readonly<TargetProps>) {
    const eyebrow = `Collection · ${completedCount} / ${totalCount} goals reached`;

    const goalsBySlot: Record<string, Goal[]> = Object.fromEntries(
        SLOT_ORDER.map((s) => [s, []]),
    );
    for (const goal of goals) {
        if (goalsBySlot[goal.slot]) {
            goalsBySlot[goal.slot].push(goal);
        }
    }

    return (
        <>
            <Head title="Collection · Goals" />
            <PageContainer>
                <CollectionHeader
                    active="target"
                    eyebrow={eyebrow}
                    headline1="Your goals,"
                    headline2="step by step."
                    activeCount={`${completedCount} / ${totalCount}`}
                />

                {SLOT_ORDER.map((slot) =>
                    goalsBySlot[slot].length > 0 ? (
                        <section key={slot} className="mt-8">
                            <SectionLabel>
                                <span className="inline-flex items-center gap-2">
                                    <Icon
                                        icon={SLOT_ICONS[slot]}
                                        width={14}
                                        height={14}
                                        aria-hidden
                                    />
                                    {SLOT_LABEL[slot]}
                                </span>
                            </SectionLabel>
                            <div className="grid gap-3.5 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                                {goalsBySlot[slot].map((goal) => (
                                    <GoalCard key={goal.id} goal={goal} />
                                ))}
                            </div>
                        </section>
                    ) : null,
                )}
            </PageContainer>
        </>
    );
}

/** Incomplete goals at or past this progress get an "almost!" nudge so the grid also reads as a to-do. */
const ALMOST_THRESHOLD = 0.75;

function GoalCard({ goal }: Readonly<{ goal: Goal }>) {
    const ratio = goalProgressRatio(goal.current, goal.target);
    const almost = !goal.is_completed && ratio >= ALMOST_THRESHOLD;

    return (
        <Card
            padding="md"
            className={cn(
                'flex h-full flex-col gap-3',
                goal.is_completed && 'border-horizon/30 bg-horizon/[0.06]',
                almost && 'border-horizon/40 ring-1 ring-horizon/30',
            )}
        >
            <div className="flex items-start justify-between gap-2">
                <h3
                    className={cn(
                        'font-display text-lg leading-tight tracking-[-0.01em] text-ink',
                        RARITY_TEXT[goal.rarity],
                    )}
                >
                    {goal.title}
                </h3>
                {goal.is_completed && (
                    <span className="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-horizon text-cream">
                        <Icon icon="mdi:check" width={14} height={14} />
                    </span>
                )}
                {almost && (
                    <span className="flex-none rounded-full bg-horizon/20 px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-[0.1em] text-horizon-deep">
                        Almost!
                    </span>
                )}
            </div>
            <p className="font-sans text-sm text-ink-2">{goal.description}</p>

            {/* Progress bar */}
            <div className="mt-auto">
                <div className="mb-1.5 flex items-baseline justify-between">
                    <span className="font-sans text-sm font-semibold tabular-nums text-ink">
                        {formatGoalNumber(goal.current)}
                        <span className="text-ink-3">/</span>
                        {formatGoalNumber(goal.target)}
                    </span>
                    <Eyebrow as="span" token="micro" tone="ink-3">
                        {goal.unit}
                    </Eyebrow>
                </div>
                <ProgressBar
                    value={ratio}
                    tone={goal.is_completed || almost ? 'horizon' : 'sky'}
                    ariaLabel={`${goal.title}: ${formatGoalNumber(goal.current)}/${formatGoalNumber(goal.target)} ${goal.unit}`}
                />
            </div>
        </Card>
    );
}

Target.layout = appLayout;
