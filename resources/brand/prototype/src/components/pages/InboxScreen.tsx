import {
    ArrowRight,
    CalendarDays,
    CalendarRange,
    ChevronDown,
    Flame,
    Footprints,
    Medal,
    Trophy,
    type LucideIcon,
} from 'lucide-react';
import { useState, type CSSProperties } from 'react';

import { FaceIcon } from '@/components/FaceIcon';
import { cn } from '@/lib/utils';

type Kind =
    | 'post_run'
    | 'weekly_recap'
    | 'monthly_recap'
    | 'streak_reminder'
    | 'unlock';
type Bucket = 'today' | 'week' | 'earlier';
type Rarity = 'common' | 'uncommon' | 'rare' | 'epic' | 'legendary';

const rarityVar = (r: Rarity) => `var(--rarity-${r})`;

type InboxItem = {
    id: number;
    kind: Kind;
    title: string;
    body: string;
    time: string;
    absolute: string;
    read: boolean;
    bucket: Bucket;
    url?: string;
    rarity?: Rarity;
    stats?: { label: string; value: string }[];
};

const KIND_LABEL: Record<Kind, string> = {
    post_run: 'post-run',
    weekly_recap: 'weekly recap',
    monthly_recap: 'monthly recap',
    streak_reminder: 'streak',
    unlock: 'unlock',
};

const KIND_ICON: Record<Kind, LucideIcon> = {
    post_run: Footprints,
    weekly_recap: CalendarDays,
    monthly_recap: CalendarRange,
    streak_reminder: Flame,
    unlock: Trophy,
};

const KIND_TONE: Record<Kind, string> = {
    post_run: 'bg-leaf/15 text-leaf-ink',
    weekly_recap: 'bg-muted text-foreground',
    monthly_recap: 'bg-muted text-foreground',
    streak_reminder: 'bg-horizon/15 text-icon-accent',
    unlock: 'bg-citrus/15 text-citrus-ink',
};

const BUCKET_LABEL: Record<Bucket, string> = {
    today: 'today',
    week: 'this week',
    earlier: 'earlier',
};

const BUCKET_ORDER: Bucket[] = ['today', 'week', 'earlier'];

const ITEMS: InboxItem[] = [
    {
        id: 6,
        kind: 'post_run',
        title: "tuesday's tempo is in.",
        body: 'legs held the pace better than they felt going in.',
        time: '2h ago',
        absolute: 'aug 25 · 07:12',
        read: false,
        bucket: 'today',
        url: '#',
        stats: [
            { label: 'distance', value: '6.4 km' },
            { label: 'pace', value: '4:52/km' },
        ],
    },
    {
        id: 5,
        kind: 'unlock',
        title: 'new badge: six-week streak.',
        body: 'six consecutive weeks with at least one run logged. longest streak yet.',
        time: '5h ago',
        absolute: 'aug 25 · 04:20',
        read: false,
        bucket: 'today',
        rarity: 'uncommon',
    },
    {
        id: 4,
        kind: 'streak_reminder',
        title: 'keep the streak alive.',
        body: 'one more run before midnight keeps the six-week streak going.',
        time: 'yesterday',
        absolute: 'aug 24 · 21:40',
        read: true,
        bucket: 'week',
        url: '#',
    },
    {
        id: 3,
        kind: 'weekly_recap',
        title: 'week 34 recap is ready.',
        body: '34.2km across 5 runs — the week fitness started climbing in earnest.',
        time: '3 days ago',
        absolute: 'aug 22 · 09:05',
        read: true,
        bucket: 'week',
        url: '#',
    },
];

const OLDER_ITEMS: InboxItem[] = [
    {
        id: 2,
        kind: 'monthly_recap',
        title: 'july recap is ready.',
        body: '142km for the month — the biggest since february.',
        time: '3 weeks ago',
        absolute: 'aug 4 · 08:30',
        read: true,
        bucket: 'earlier',
        url: '#',
    },
    {
        id: 1,
        kind: 'post_run',
        title: "saturday's long run is in.",
        body: 'highest weekly mileage in six weeks.',
        time: '1 month ago',
        absolute: 'jul 26 · 08:15',
        read: true,
        bucket: 'earlier',
        url: '#',
        stats: [
            { label: 'distance', value: '16 km' },
            { label: 'pace', value: '5:41/km' },
        ],
    },
];

function InboxRow({ item }: Readonly<{ item: InboxItem }>) {
    const Icon = KIND_ICON[item.kind];
    const [showAbsolute, setShowAbsolute] = useState(false);

    return (
        <div
            className={cn(
                'rounded-[14px] border p-4 shadow-e1',
                item.read
                    ? 'border-border-strong bg-card'
                    : 'border-horizon bg-horizon/[0.07]',
            )}
        >
            <div className="flex gap-3">
                <span
                    aria-hidden
                    className={cn(
                        'flex size-9 shrink-0 items-center justify-center rounded-[10px]',
                        KIND_TONE[item.kind],
                    )}
                >
                    <Icon className="size-[18px]" aria-hidden />
                </span>

                <div className="min-w-0 flex-1">
                    <div className="flex items-baseline justify-between gap-3">
                        {item.kind === 'unlock' && item.rarity ? (
                            <span
                                className="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-mono text-[8.5px] leading-[1.2] font-extrabold tracking-[.04em] text-foreground uppercase"
                                style={
                                    {
                                        background: `color-mix(in oklab, ${rarityVar(item.rarity)} 18%, var(--muted))`,
                                    } as CSSProperties
                                }
                            >
                                <Medal
                                    className="size-2.5"
                                    style={
                                        {
                                            color: rarityVar(item.rarity),
                                        } as CSSProperties
                                    }
                                    aria-hidden
                                />
                                {item.rarity} unlock
                            </span>
                        ) : (
                            <span className="font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.06em] text-foreground uppercase">
                                {KIND_LABEL[item.kind]}
                            </span>
                        )}
                        <button
                            type="button"
                            onClick={() => setShowAbsolute((v) => !v)}
                            className="shrink-0 font-mono text-[10px] leading-[1.2] tabular-nums text-foreground"
                        >
                            {showAbsolute ? item.absolute : item.time}
                        </button>
                    </div>

                    <h2 className="mt-1 font-sans text-[13px] leading-[1.3] font-semibold text-foreground">
                        {item.title}
                    </h2>

                    {item.stats && (
                        <div className="mt-1.5 flex flex-wrap gap-1.5">
                            {item.stats.map((stat) => (
                                <span
                                    key={stat.label}
                                    className="inline-flex items-baseline gap-1 rounded-full bg-muted px-2.25 py-1 font-mono text-[10px] leading-[1.2] font-bold text-foreground"
                                >
                                    {stat.value}
                                    <span className="text-[8px] font-extrabold tracking-[.03em] text-foreground uppercase">
                                        {stat.label}
                                    </span>
                                </span>
                            ))}
                        </div>
                    )}

                    <p className="mt-1.5 line-clamp-3 font-sans text-[12px] leading-[1.5] text-foreground">
                        {item.body}
                    </p>

                    {item.url && (
                        <a
                            href="#"
                            className="mt-2.5 inline-flex items-center gap-1 rounded-full border border-border-strong bg-transparent px-3 py-1.5 font-sans text-[10.5px] leading-[1.2] font-bold text-foreground"
                        >
                            open
                            <ArrowRight className="size-3" aria-hidden />
                        </a>
                    )}
                </div>

                {!item.read && (
                    <span
                        aria-label="Unread"
                        role="status"
                        className="mt-1 size-2 shrink-0 rounded-full bg-icon-accent"
                    />
                )}
            </div>
        </div>
    );
}

function EmptyInboxCard() {
    return (
        <div className="mt-2 flex items-center gap-3.5 rounded-[14px] border border-border-strong bg-card p-4.5 shadow-e1">
            <FaceIcon
                size={40}
                ring="var(--horizon)"
                fill="var(--card)"
                feature="var(--foreground)"
            />
            <div>
                <p className="m-0 font-serif text-base leading-[1.2] font-semibold text-foreground italic">
                    nothing here yet.
                </p>
                <p className="mt-1 text-xs leading-[1.5] text-foreground">
                    every run, recap, and unlock lands here on its own. nothing
                    for you to do.
                </p>
            </div>
        </div>
    );
}

export function InboxScreen({
    inboxState,
}: Readonly<{ inboxState: 'populated' | 'empty' }>) {
    const [olderRevealed, setOlderRevealed] = useState(false);
    const items = olderRevealed ? [...ITEMS, ...OLDER_ITEMS] : ITEMS;
    const unread = items.filter((item) => !item.read).length;

    return (
        <div className="px-4 pt-16 pb-7 @min-[900px]:mx-auto @min-[900px]:max-w-[760px] @min-[900px]:px-6 @min-[900px]:pt-6 @min-[900px]:pb-24">
            <div className="mt-3 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.12em] text-foreground uppercase">
                {inboxState === 'populated' && unread > 0
                    ? `inbox · ${unread} unread`
                    : 'inbox'}
            </div>
            <h1 className="m-0 mt-2 mb-4 font-serif text-[24px] leading-[1.15] font-semibold text-foreground italic">
                everything i told you,
                <br />
                <em className="text-icon-accent">still here.</em>
            </h1>

            {inboxState === 'empty' ? (
                <EmptyInboxCard />
            ) : (
                <>
                    {BUCKET_ORDER.map((bucket) => {
                        const bucketItems = items.filter(
                            (item) => item.bucket === bucket,
                        );
                        if (bucketItems.length === 0) {
                            return null;
                        }
                        return (
                            <div key={bucket} className="mb-3.5">
                                <div className="mb-2 font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.08em] text-foreground uppercase">
                                    {BUCKET_LABEL[bucket]}
                                </div>
                                <div className="flex flex-col gap-2.5">
                                    {bucketItems.map((item) => (
                                        <InboxRow key={item.id} item={item} />
                                    ))}
                                </div>
                            </div>
                        );
                    })}

                    {!olderRevealed && (
                        <div className="mt-1 flex justify-center">
                            <button
                                type="button"
                                onClick={() => setOlderRevealed(true)}
                                className="inline-flex items-center gap-1.25 rounded-full border border-border-strong bg-card px-4.5 py-2.25 font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.05em] text-foreground uppercase shadow-e1"
                            >
                                load older
                                <ChevronDown className="size-3" aria-hidden />
                            </button>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
