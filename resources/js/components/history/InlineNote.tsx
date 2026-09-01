import { type ReactNode } from 'react';

import BackLink from '@/components/ui/BackLink';
import { Card } from '@/components/ui/card';
import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { formatIdDate } from '@/lib/pace';

/** Matches FeedFilters::RANGE_DAYS on the backend. */
export type RangeFilterValue = '8w' | '12w' | '6m' | '1y' | 'all';

const RANGE_LABELS: Record<RangeFilterValue, string> = {
    '8w': 'last 2 months',
    '12w': 'last 3 months',
    '6m': 'last 6 months',
    '1y': 'full year',
    all: 'all runs',
};

interface InlineNoteProps {
    icon: string;
    children: ReactNode;
    action?: ReactNode;
    className?: string;
}

export default function InlineNote({
    icon,
    children,
    action,
    className,
}: Readonly<InlineNoteProps>) {
    return (
        <Card className={cn('flex items-center gap-2.5 px-4 py-3', className)}>
            <Icon
                icon={icon}
                width={16}
                height={16}
                className="shrink-0 text-text-3"
                aria-hidden
            />
            <p className="font-sans text-sm text-text-2">{children}</p>
            {action}
        </Card>
    );
}

export function RangeWidenedNote({
    rangeFilter,
}: Readonly<{ rangeFilter: RangeFilterValue }>) {
    const label = RANGE_LABELS[rangeFilter];
    const message =
        rangeFilter === 'all'
            ? 'showing all your runs, so your most recent one stays visible.'
            : `Range automatically widened to ${label} so your latest run stays visible.`;
    return (
        <InlineNote icon="mdi:arrow-expand-horizontal">{message}</InlineNote>
    );
}

/**
 * Shown when the page is scoped to one week, which only happens via a deep link
 * (the weekly-recap notification). Without it the view would look like a history
 * that mysteriously lost most of its runs, so it names the week and offers the
 * way back to the full list.
 */
export function WeekFocusNote({
    weekEnding,
}: Readonly<{ weekEnding: string }>) {
    const sunday = new Date(`${weekEnding}T00:00:00`);
    const monday = new Date(sunday);
    monday.setDate(monday.getDate() - 6);

    return (
        <InlineNote
            icon="mdi:calendar-week"
            className="mb-6 flex-wrap"
            action={
                <BackLink href="/history" tone="accent">
                    View all runs
                </BackLink>
            }
        >
            Viewing the week of {formatIdDate(monday.toISOString())} -{' '}
            {formatIdDate(sunday.toISOString())}.
        </InlineNote>
    );
}
