import { Link } from '@inertiajs/react';
import { useState } from 'react';

import type { InboxItem, NotificationKind } from '@/types/inertia';

import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import PillLink from '@/components/ui/PillLink';
import { cn } from '@/lib/cn';
import {
    formatAbsoluteId,
    formatIdDate,
    formatKm,
    formatPace,
    formatRelativeId,
    paceSecPerKm,
} from '@/lib/pace';
import { ICON_TONE, type Tone } from '@/lib/tones';

const KIND_LABEL: Record<NotificationKind, string> = {
    post_run: 'Post-run',
    weekly_recap: 'Weekly Recap',
    monthly_recap: 'Monthly Recap',
    streak_reminder: 'Streak',
    plan_clamp: 'Plan',
    strava_disconnected: 'Strava',
    test: 'Test',
};

const KIND_ICON: Record<NotificationKind, string> = {
    post_run: 'mdi:run',
    weekly_recap: 'mdi:calendar-week',
    monthly_recap: 'mdi:calendar-blank-outline',
    streak_reminder: 'mdi:fire',
    plan_clamp: 'mdi:sleep',
    strava_disconnected: 'mdi:sync-off',
    test: 'mdi:bell-outline',
};

const KIND_TONE: Record<NotificationKind, Tone> = {
    post_run: 'brand',
    weekly_recap: 'neutral',
    monthly_recap: 'neutral',
    streak_reminder: 'accent',
    plan_clamp: 'neutral',
    strava_disconnected: 'neutral',
    test: 'neutral',
};

interface InboxRowProps {
    item: InboxItem;
    read: boolean;
    /** The deep-link target: outlined so a push tap lands somewhere obvious. */
    focused: boolean;
    onOpen: (item: InboxItem) => void;
}

/** The distance/pace pair the prototype draws on a post-run row. */
function statsFor(item: InboxItem): { label: string; value: string }[] {
    if (item.kind !== 'post_run' || item.distance_m === null) {
        return [];
    }

    const stats = [
        { label: 'Distance', value: `${formatKm(item.distance_m, 1)} km` },
    ];
    const pace = paceSecPerKm(item.moving_time_s, item.distance_m);
    if (pace !== null) {
        stats.push({ label: 'Pace', value: `${formatPace(pace)}/km` });
    }

    return stats;
}

export default function InboxRow({
    item,
    read,
    focused,
    onOpen,
}: Readonly<InboxRowProps>) {
    const [showAbsolute, setShowAbsolute] = useState(false);
    const stats = statsFor(item);

    return (
        <Card
            as="article"
            className={cn(
                'relative scroll-mt-24 transition',
                !read && 'border-horizon bg-horizon/[0.07]',
                focused && 'ring-2 ring-horizon',
            )}
        >
            {item.url && (
                /* A pointer affordance only: the visible "open" pill below is the
                   accessible control, so this overlay stays out of the a11y tree
                   rather than reading the row's target twice. */
                <Link
                    href={item.url}
                    onClick={() => onOpen(item)}
                    aria-hidden
                    tabIndex={-1}
                    className="absolute inset-0 z-10 rounded-md"
                />
            )}

            <div className="flex gap-3">
                <span
                    aria-hidden
                    className={cn(
                        'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg',
                        ICON_TONE[KIND_TONE[item.kind]],
                    )}
                >
                    <Icon icon={KIND_ICON[item.kind]} width={18} height={18} />
                </span>

                <div className="min-w-0 flex-1">
                    <div className="flex items-baseline justify-between gap-3">
                        <Eyebrow token="micro">{KIND_LABEL[item.kind]}</Eyebrow>
                        <button
                            type="button"
                            onClick={() => setShowAbsolute((prev) => !prev)}
                            className="relative z-20 shrink-0 font-mono text-xs tabular-nums text-text-3"
                        >
                            <time
                                dateTime={item.created_at ?? undefined}
                                title={formatIdDate(item.created_at, 'long')}
                            >
                                {showAbsolute
                                    ? formatAbsoluteId(item.created_at)
                                    : formatRelativeId(item.created_at)}
                            </time>
                        </button>
                    </div>

                    <h2 className="mt-1 font-sans text-sm font-semibold text-foreground">
                        {item.title}
                    </h2>

                    {stats.length > 0 && (
                        <div className="mt-1.5 flex flex-wrap gap-1.5">
                            {stats.map((stat) => (
                                <span
                                    key={stat.label}
                                    className="inline-flex items-baseline gap-1 rounded-full bg-muted px-2 py-1 font-mono text-xs font-bold tabular-nums text-foreground"
                                >
                                    {stat.value}
                                    <span className="text-label-micro text-text-3">
                                        {stat.label}
                                    </span>
                                </span>
                            ))}
                        </div>
                    )}

                    {item.body && (
                        <p className="mt-1.5 line-clamp-3 font-sans text-sm leading-relaxed text-text-2">
                            {item.body}
                        </p>
                    )}

                    {item.url && (
                        <div className="relative z-20 mt-2.5 flex w-fit flex-wrap items-center gap-2">
                            <PillLink
                                href={item.url}
                                tone="outline"
                                size="sm"
                                onClick={() => onOpen(item)}
                            >
                                open
                                <Icon
                                    icon="mdi:arrow-right"
                                    className="size-3"
                                    aria-hidden
                                />
                            </PillLink>
                        </div>
                    )}
                </div>

                {!read && (
                    <span
                        className="mt-1 h-2 w-2 shrink-0 rounded-full bg-icon-accent"
                        aria-label="Unread"
                        role="status"
                    />
                )}
            </div>
        </Card>
    );
}
