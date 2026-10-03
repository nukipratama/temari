import { Deferred, Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useEffect, useState } from 'react';

import type { InboxItem, SharedProps } from '@/types/inertia';

import { BUCKET_LABEL, groupByBucket } from '@/components/inbox/inboxBuckets';
import InboxRow from '@/components/inbox/InboxRow';
import EmptyPanel from '@/components/ui/EmptyPanel';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import { SkeletonRows } from '@/components/ui/Skeleton';
import { appLayout } from '@/layouts/appLayout';
import { postJson } from '@/lib/http';
import { useTodayIso } from '@/lib/pace';
import { pillButtonVariants } from '@/lib/variants';

/** Rows each "load older" press adds — mirrors InboxController::PER_PAGE. */
const PER_PAGE = 20;

interface InboxProps {
    notifications?: InboxItem[];
    /** Size of the window the server shipped. */
    shown?: number;
    /** Whether anything sits behind that window. */
    hasOlder?: boolean;
    /** Deep-link target from a push tap (`/inbox?item=123`). */
    focusId: number | null;
}

function sendRead(id: number): Promise<void> {
    return postJson(`/api/notifications/${id}/read`)
        .then(() => router.reload({ only: ['unreadNotifications'] }))
        .catch(() => undefined);
}

function sendReadAll(): Promise<void> {
    return postJson('/api/notifications/read-all')
        .then(() => router.reload({ only: ['unreadNotifications'] }))
        .catch(() => undefined);
}

export default function Inbox({
    notifications = [],
    shown,
    hasOlder,
    focusId,
}: Readonly<InboxProps>) {
    const { props } = usePage<SharedProps>();
    const unread = props.unreadNotifications ?? 0;
    const today = useTodayIso();

    const focusTarget =
        notifications.find((item) => item.id === focusId) ?? null;
    // A deep link is the user arriving at that one row, so it counts as read.
    const focusUnreadId =
        focusTarget !== null && focusTarget.read_at === null
            ? focusTarget.id
            : null;

    const [readIds, setReadIds] = useState<ReadonlySet<number>>(
        () => new Set(),
    );

    // The deep-linked row counts as read from the moment it lands, which is a
    // beat after paint now that the window is deferred.
    const isRead = (item: InboxItem) =>
        item.read_at !== null ||
        readIds.has(item.id) ||
        item.id === focusUnreadId;

    const markRead = (item: InboxItem) => {
        if (isRead(item)) {
            return;
        }
        setReadIds((previous) => new Set(previous).add(item.id));
        void sendRead(item.id);
    };

    const markAllRead = () => {
        setReadIds(
            (previous) =>
                new Set([...previous, ...notifications.map((item) => item.id)]),
        );
        void sendReadAll();
    };

    useEffect(() => {
        if (focusId === null) {
            return;
        }
        document
            .getElementById(`inbox-item-${focusId}`)
            ?.scrollIntoView({ block: 'center' });
        if (focusUnreadId !== null) {
            void sendRead(focusUnreadId);
        }
    }, [focusId, focusUnreadId]);

    return (
        <>
            <Head title="Inbox" />
            <PageContainer>
                <PageHero
                    eyebrow={
                        <div className="mb-3.5 flex items-baseline justify-between gap-3">
                            <Eyebrow token="hero" tone="ink-2">
                                {unread > 0
                                    ? `Inbox · ${unread} unread`
                                    : 'Inbox'}
                            </Eyebrow>
                            {unread > 0 && (
                                <button
                                    type="button"
                                    onClick={markAllRead}
                                    className="focus-ring hit-area shrink-0 rounded-xs font-mono text-xs font-semibold text-text-3 transition hover:text-foreground"
                                >
                                    mark all read
                                </button>
                            )}
                        </div>
                    }
                    size="quote-lg"
                    italic
                >
                    everything i told you,
                    <br />
                    <em className="italic text-icon-accent">still here.</em>
                </PageHero>

                <Deferred
                    data={['notifications', 'shown', 'hasOlder']}
                    fallback={<SkeletonRows count={4} className="mt-4" />}
                >
                    {() =>
                        notifications.length === 0 ? (
                            <EmptyPanel
                                face
                                layout="horizontal"
                                title="nothing here yet."
                                body="every run and recap lands here on its own. nothing for you to do."
                                className="mt-4"
                            />
                        ) : (
                            <>
                                <div className="mt-4 flex flex-col gap-3.5">
                                    {groupByBucket(notifications, today).map(
                                        ({ bucket, items }) => (
                                            <div key={bucket}>
                                                <Eyebrow
                                                    token="small"
                                                    className="mb-2"
                                                >
                                                    {BUCKET_LABEL[bucket]}
                                                </Eyebrow>
                                                <div className="flex flex-col divide-y divide-border">
                                                    {items.map((item) => (
                                                        <div
                                                            key={item.id}
                                                            id={`inbox-item-${item.id}`}
                                                        >
                                                            <InboxRow
                                                                item={item}
                                                                read={isRead(
                                                                    item,
                                                                )}
                                                                focused={
                                                                    item.id ===
                                                                    focusId
                                                                }
                                                                onOpen={
                                                                    markRead
                                                                }
                                                            />
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        ),
                                    )}
                                </div>

                                {hasOlder && <LoadOlder shown={shown!} />}
                            </>
                        )
                    }
                </Deferred>
            </PageContainer>
        </>
    );
}

/**
 * P3: a real page, not a reveal. Each press asks the server for twenty more
 * rows; `preserveScroll` keeps what has already been read where it was, and
 * only the list props are refetched.
 */
function LoadOlder({ shown }: Readonly<{ shown: number }>) {
    return (
        <div className="mt-1 flex justify-center">
            <Link
                href={`/inbox?shown=${shown + PER_PAGE}`}
                preserveScroll
                preserveState
                only={['notifications', 'shown', 'hasOlder']}
                className={pillButtonVariants({ tone: 'muted', size: 'xs' })}
            >
                load older
                <Icon icon={ChevronDown} width={12} height={12} aria-hidden />
            </Link>
        </div>
    );
}

Inbox.layout = appLayout;
