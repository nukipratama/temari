import { useState } from 'react';

import type { InboxItem, NotificationKind } from '@/types/inertia';

import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import PillLink from '@/components/ui/PillLink';
import { cn } from '@/lib/cn';
import { formatAbsoluteId, formatIdDate, formatRelativeId } from '@/lib/pace';
import { RARITY_LABELS } from '@/lib/runcard';
import { ICON_TONE, type Tone } from '@/lib/tones';
import { rarityVariants } from '@/lib/variants';

const KIND_LABEL: Record<NotificationKind, string> = {
    post_run: 'Post-run',
    weekly_recap: 'Weekly Recap',
    monthly_recap: 'Monthly Recap',
    streak_reminder: 'Streak',
    unlock: 'Unlock',
    test: 'Test',
};

const KIND_ICON: Record<NotificationKind, string> = {
    post_run: 'mdi:run',
    weekly_recap: 'mdi:calendar-week',
    monthly_recap: 'mdi:calendar-blank-outline',
    streak_reminder: 'mdi:fire',
    unlock: 'mdi:trophy-outline',
    test: 'mdi:bell-outline',
};

const KIND_TONE: Record<NotificationKind, Tone> = {
    post_run: 'brand',
    weekly_recap: 'neutral',
    monthly_recap: 'neutral',
    streak_reminder: 'accent',
    unlock: 'pop',
    test: 'neutral',
};

interface InboxRowProps {
    item: InboxItem;
    read: boolean;
    /** The deep-link target: outlined so a push tap lands somewhere obvious. */
    focused: boolean;
    onOpen: (item: InboxItem) => void;
}

export default function InboxRow({
    item,
    read,
    focused,
    onOpen,
}: Readonly<InboxRowProps>) {
    const [showAbsolute, setShowAbsolute] = useState(false);
    const showRarityBadge = item.kind === 'unlock' && item.rarity !== null;

    return (
        <Card
            as="article"
            padding="panel"
            className={cn(
                'scroll-mt-24 transition',
                !read && 'border-horizon bg-horizon/[0.07]',
                focused && 'ring-2 ring-horizon',
            )}
        >
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
                        {showRarityBadge && item.rarity !== null ? (
                            <span
                                className={cn(
                                    'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-label-micro',
                                    rarityVariants.flag({
                                        rarity: item.rarity,
                                    }),
                                )}
                            >
                                <Icon
                                    icon="mdi:medal"
                                    width={11}
                                    height={11}
                                    aria-hidden
                                />
                                {RARITY_LABELS[item.rarity]} Unlock
                            </span>
                        ) : (
                            <Eyebrow token="micro">
                                {KIND_LABEL[item.kind]}
                            </Eyebrow>
                        )}
                        <button
                            type="button"
                            onClick={() => setShowAbsolute((prev) => !prev)}
                            className="shrink-0 font-mono text-xs tabular-nums text-text-3"
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

                    {item.body && (
                        <p className="mt-1 line-clamp-3 font-sans text-sm leading-relaxed text-text-2">
                            {item.body}
                        </p>
                    )}

                    {item.url && (
                        <div className="mt-3 flex flex-wrap items-center gap-2">
                            <PillLink
                                href={item.url}
                                tone="outline"
                                size="sm"
                                onClick={() => onOpen(item)}
                            >
                                Open
                            </PillLink>
                        </div>
                    )}
                </div>

                {!read && (
                    <span
                        className="mt-1 h-2 w-2 shrink-0 rounded-full bg-horizon"
                        aria-label="Unread"
                        role="status"
                    />
                )}
            </div>
        </Card>
    );
}
