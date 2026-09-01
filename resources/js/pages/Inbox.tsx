import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import type { InboxItem, SharedProps } from '@/types/inertia';

import { BUCKET_LABEL, groupByBucket } from '@/components/inbox/inboxBuckets';
import InboxRow from '@/components/inbox/InboxRow';
import EmptyPanel from '@/components/ui/EmptyPanel';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import { appLayout } from '@/layouts/appLayout';
import { postJson } from '@/lib/http';

/** Rows each "load older" press adds — mirrors InboxController::PER_PAGE. */
const PER_PAGE = 20;

interface InboxProps {
    notifications: InboxItem[];
    /** Size of the window the server shipped. */
    shown: number;
    /** Whether anything sits behind that window. */
    hasOlder: boolean;
    /** Deep-link target from a push tap (`/inbox?item=123`). */
    focusId: number | null;
}

function sendRead(id: number): Promise<void> {
    return postJson(`/api/notifications/${id}/read`)
        .then(() => router.reload({ only: ['unreadNotifications'] }))
        .catch(() => undefined);
}

export default function Inbox({
    notifications,
    shown,
    hasOlder,
    focusId,
}: Readonly<InboxProps>) {
    const { props } = usePage<SharedProps>();
    const unread = props.unreadNotifications ?? 0;

    const focusTarget =
        notifications.find((item) => item.id === focusId) ?? null;
    // A deep link is the user arriving at that one row, so it counts as read.
    const focusUnreadId =
        focusTarget !== null && focusTarget.read_at === null
            ? focusTarget.id
            : null;

    const [readIds, setReadIds] = useState<ReadonlySet<number>>(() =>
        focusUnreadId === null ? new Set() : new Set([focusUnreadId]),
    );

    const isRead = (item: InboxItem) =>
        item.read_at !== null || readIds.has(item.id);

    const markRead = (item: InboxItem) => {
        if (isRead(item)) {
            return;
        }
        setReadIds((previous) => new Set(previous).add(item.id));
        void sendRead(item.id);
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
                    eyebrow={unread > 0 ? `Inbox · ${unread} unread` : 'Inbox'}
                    size="quote-lg"
                    italic
                >
                    everything i told you,
                    <br />
                    <em className="italic text-icon-accent">still here.</em>
                </PageHero>

                {notifications.length === 0 ? (
                    <EmptyPanel
                        face
                        title="Nothing here yet."
                        body="Every run, recap, and unlock lands here on its own. Nothing for you to do."
                        className="mt-4"
                    />
                ) : (
                    <>
                        <div className="mt-4 flex flex-col gap-3.5">
                            {groupByBucket(notifications).map(
                                ({ bucket, items }) => (
                                    <div key={bucket}>
                                        <Eyebrow token="small" className="mb-2">
                                            {BUCKET_LABEL[bucket]}
                                        </Eyebrow>
                                        <div className="flex flex-col gap-2.5">
                                            {items.map((item) => (
                                                <div
                                                    key={item.id}
                                                    id={`inbox-item-${item.id}`}
                                                >
                                                    <InboxRow
                                                        item={item}
                                                        read={isRead(item)}
                                                        focused={
                                                            item.id === focusId
                                                        }
                                                        onOpen={markRead}
                                                    />
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                ),
                            )}
                        </div>

                        {hasOlder && <LoadOlder shown={shown} />}
                    </>
                )}
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
                className="pressable focus-ring inline-flex items-center gap-1.25 rounded-full border border-border-strong bg-card px-4.5 py-2.25 font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.05em] text-foreground uppercase shadow-e1"
            >
                Load older
                <Icon
                    icon="mdi:chevron-down"
                    width={12}
                    height={12}
                    aria-hidden
                />
            </Link>
        </div>
    );
}

Inbox.layout = appLayout;
