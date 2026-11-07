import type { ReactNode } from 'react';

import type { HardBudget } from '@/lib/plan';

import { cn } from '@/lib/cn';

const CAP_SHARE_PCT = 30;

function LegendItem({
    dot,
    children,
}: Readonly<{ dot: string; children: ReactNode }>) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span
                aria-hidden
                className={cn('inline-block size-2 rounded-full', dot)}
            />
            {children}
        </span>
    );
}

/**
 * The current week's hard minutes against the cap locked at its first
 * regeneration: what has been run, what the days ahead have booked, and what
 * is left or over. The share counts spent and booked minutes against the
 * week's minutes, so a full cap reads as 30%.
 */
export default function HardMinuteWallet({
    budget,
}: Readonly<{ budget: HardBudget }>) {
    const {
        cap_minutes: cap,
        spent_minutes: spent,
        booked_minutes: booked,
        left_minutes: left,
    } = budget;
    const used = spent + booked;
    const scale = Math.max(cap, used, 1);
    const share = cap > 0 ? Math.round((used / cap) * CAP_SHARE_PCT) : 0;
    const width = (minutes: number) => `${(minutes / scale) * 100}%`;
    const balance = left >= 0 ? `${left} left` : `${-left} over`;

    return (
        <div>
            <p className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                <span className="text-label-micro text-text-3">
                    hard min this week
                </span>
                <span className="font-mono text-xs text-foreground">
                    {`${used} / ${cap} hard min · ${share}% hard, cap ${CAP_SHARE_PCT}%`}
                </span>
            </p>
            <div
                role="img"
                aria-label={`${spent} hard minutes spent, ${booked} booked, ${balance} of a ${cap}-minute cap`}
                className="relative mt-1.5 flex h-2 overflow-hidden rounded-full bg-muted"
            >
                <span
                    className="h-full bg-ember"
                    style={{ width: width(spent) }}
                />
                <span
                    className="h-full bg-ember/25"
                    style={{ width: width(booked) }}
                />
                {used > cap && (
                    <span
                        className="absolute inset-y-0 w-0.5 bg-foreground"
                        style={{ left: width(cap) }}
                    />
                )}
            </div>
            <p className="mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5 text-label-micro text-text-2">
                <LegendItem dot="bg-ember">{spent} spent</LegendItem>
                {booked > 0 && (
                    <LegendItem dot="bg-ember/25">{booked} booked</LegendItem>
                )}
                <LegendItem
                    dot={
                        left >= 0
                            ? 'bg-muted ring-1 ring-border-strong'
                            : 'bg-foreground'
                    }
                >
                    {balance}
                </LegendItem>
                <span className="text-text-3">cap locked for the week</span>
            </p>
        </div>
    );
}
