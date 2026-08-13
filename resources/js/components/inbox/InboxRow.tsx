import { Icon } from '@iconify/react';

import type { InboxItem, NotificationKind } from '@/types/inertia';

import Card from '@/components/ui/Card';
import Eyebrow from '@/components/ui/Eyebrow';
import PillButton from '@/components/ui/PillButton';
import PillLink from '@/components/ui/PillLink';
import { cn } from '@/lib/cn';
import { formatIdDate, formatRelativeId } from '@/lib/pace';
import { ICON_TONE, type Tone } from '@/lib/tones';

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
    replaying: boolean;
    onReplay: (item: InboxItem) => void;
    onOpen: (item: InboxItem) => void;
}

export default function InboxRow({
    item,
    read,
    focused,
    replaying,
    onReplay,
    onOpen,
}: Readonly<InboxRowProps>) {
    const replayLabel = item.unlock ? 'Replay Unlock' : 'Replay Reveal';
    const canReplay = item.unlock !== null || item.run_card_id !== null;

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
                        <Eyebrow token="micro">{KIND_LABEL[item.kind]}</Eyebrow>
                        <time
                            dateTime={item.created_at ?? undefined}
                            title={formatIdDate(item.created_at, 'long')}
                            className="shrink-0 font-mono text-xs tabular-nums text-ink-3"
                        >
                            {formatRelativeId(item.created_at)}
                        </time>
                    </div>

                    <h2 className="mt-1 font-sans text-sm font-semibold text-ink">
                        {item.title}
                    </h2>

                    {item.body && (
                        <p className="mt-1 line-clamp-3 font-sans text-sm leading-relaxed text-ink-2">
                            {item.body}
                        </p>
                    )}

                    {(canReplay || item.url) && (
                        <div className="mt-3 flex flex-wrap items-center gap-2">
                            {canReplay && (
                                <PillButton
                                    tone="horizon"
                                    size="sm"
                                    disabled={replaying}
                                    onClick={() => onReplay(item)}
                                >
                                    <Icon
                                        icon={
                                            replaying
                                                ? 'mdi:loading'
                                                : 'mdi:play-circle-outline'
                                        }
                                        width={15}
                                        height={15}
                                        aria-hidden
                                        className={cn(
                                            replaying && 'animate-spin',
                                        )}
                                    />
                                    {replaying ? 'Replaying' : replayLabel}
                                </PillButton>
                            )}
                            {item.url && (
                                <PillLink
                                    href={item.url}
                                    tone="outline"
                                    size="sm"
                                    onClick={() => onOpen(item)}
                                >
                                    Open
                                </PillLink>
                            )}
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
