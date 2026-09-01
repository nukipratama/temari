import type {
    ActivityDetail,
    BriefingResult,
    TrainingLoad,
    WeeklySnapshot,
} from '@/types/inertia';

import LastRunCard from '@/components/dashboard/LastRunCard';
import TrainingLoadCard from '@/components/dashboard/TrainingLoadCard';
import VitalBars from '@/components/dashboard/VitalBars';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import { useCountUp } from '@/hooks/useCountUp';

function StatFigure({
    value,
    label,
}: Readonly<{ value: string; label: string }>) {
    return (
        <span className="inline-flex items-baseline gap-1">
            <b className="font-mono text-[15px] font-extrabold tabular-nums text-foreground">
                {value}
            </b>
            <span className="font-mono text-[10px] uppercase tracking-[0.05em] text-foreground">
                {label}
            </span>
        </span>
    );
}

/**
 * The prototype's "this week's stats" disclosure. It renders **closed** — the
 * prototype passes no `defaultOpen` (`TodayScreen.tsx:464`), which the
 * 2026-08-31 amendment to `plan/parity/README.md` settled as what ships.
 * Inside: the week's stat strip, the three vital bars, and the last-run /
 * condition pair.
 */
export default function WeekStatsDisclosure({
    briefing,
    load,
    snapshot,
    lastRun,
}: Readonly<{
    briefing: BriefingResult;
    load: TrainingLoad | null;
    snapshot: WeeklySnapshot | null;
    lastRun: ActivityDetail | null;
}>) {
    const runs = useCountUp(snapshot?.runs ?? 0);
    const km = useCountUp(snapshot?.distance_km ?? 0);
    const trimp = useCountUp(snapshot?.weekly_trimp ?? 0);

    const runsDisplay = snapshot ? Math.round(runs).toString() : '—';
    const kmDisplay = snapshot ? km.toFixed(1) : '—';
    const trimpDisplay =
        snapshot?.weekly_trimp != null ? Math.round(trimp).toString() : '—';

    return (
        <Collapsible>
            <CollapsibleTrigger className="group focus-ring flex w-full items-center justify-between gap-2 rounded-md border border-border bg-card px-3.5 py-3 text-xs text-foreground shadow-e1">
                <span>
                    <b className="font-bold">this week&apos;s stats</b> ·{' '}
                    {runsDisplay} runs · {kmDisplay} km
                </span>
                <Icon
                    icon="mdi:chevron-down"
                    width={18}
                    height={18}
                    className="flex-none transition-transform group-aria-expanded:rotate-180"
                    aria-hidden
                />
            </CollapsibleTrigger>
            <CollapsibleContent className="pt-3">
                <Card padding="panel">
                    <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                        <StatFigure value={runsDisplay} label="runs" />
                        <span aria-hidden className="text-foreground">
                            ·
                        </span>
                        <StatFigure value={kmDisplay} label="km" />
                        <span aria-hidden className="text-foreground">
                            ·
                        </span>
                        <StatFigure value={trimpDisplay} label="trimp" />
                    </div>
                    <div aria-hidden className="my-3 h-px bg-border" />
                    <VitalBars briefing={briefing} load={load} />
                </Card>

                <div className="mt-2 grid grid-cols-2 gap-2">
                    {lastRun && <LastRunCard run={lastRun} />}
                    <TrainingLoadCard load={load} snapshot={snapshot} />
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}
