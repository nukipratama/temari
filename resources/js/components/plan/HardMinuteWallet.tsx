import type { ReactNode } from 'react';

import type { HardBudget } from '@/lib/plan';

import { cn } from '@/lib/cn';

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
 * regeneration, leading with what is left or over, then what has been run
 * and what the days ahead have booked.
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
    const width = (minutes: number) => `${(minutes / scale) * 100}%`;
    const balance = left >= 0 ? `${left} left` : `${-left} over`;

    return (
        <div>
            <p className="text-label-micro text-text-3">hard min this week</p>
            <p className="mt-1 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                <span className="text-stat-sm">{balance}</span>
                <span className="flex flex-wrap gap-x-3 gap-y-0.5 font-mono text-xs text-text-2">
                    <LegendItem dot="bg-ember">{spent} spent</LegendItem>
                    {booked > 0 && (
                        <LegendItem dot="bg-ember/18">
                            {booked} booked
                        </LegendItem>
                    )}
                    <span>cap {cap}</span>
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
                    className="h-full bg-ember/18"
                    style={{ width: width(booked) }}
                />
                {used > cap && (
                    <span
                        className="absolute inset-y-0 w-0.5 bg-foreground"
                        style={{ left: width(cap) }}
                    />
                )}
            </div>
        </div>
    );
}
