import { Head, router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useEffect, useState } from 'react';

import type {
    InboxItem,
    PaginatedResponse,
    UnlockFlash,
} from '@/types/inertia';

import AccessoryUnlockModal from '@/components/celebrations/AccessoryUnlockModal';
import InboxRow from '@/components/inbox/InboxRow';
import EmptyPanel from '@/components/ui/EmptyPanel';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import PillLink from '@/components/ui/PillLink';
import { appLayout } from '@/layouts/appLayout';
import { postJson } from '@/lib/http';
import { fadeInUp, staggerContainer } from '@/lib/motion';

interface InboxProps {
    notifications: PaginatedResponse<InboxItem>;
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
    focusId,
}: Readonly<InboxProps>) {
    const items = notifications.data;
    const focusTarget = items.find((item) => item.id === focusId) ?? null;
    // A deep link is the user arriving at that one row, so it counts as read.
    const focusUnreadId =
        focusTarget !== null && focusTarget.read_at === null
            ? focusTarget.id
            : null;

    const [readIds, setReadIds] = useState<ReadonlySet<number>>(() =>
        focusUnreadId === null ? new Set() : new Set([focusUnreadId]),
    );
    const [replayingId, setReplayingId] = useState<number | null>(null);
    const [unlockReplay, setUnlockReplay] = useState<UnlockFlash | null>(null);

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

    // Both replays re-run the original celebration rather than describing it:
    // an unlock re-opens the takeover it was granted with, a post-run re-arms
    // the reveal through the same endpoint the run page uses, then reloads
    // `pendingReveal` so AppShell plays it.
    const replay = (item: InboxItem) => {
        markRead(item);

        if (item.unlock !== null) {
            setUnlockReplay(item.unlock);
            return;
        }
        if (item.run_card_id === null || replayingId !== null) {
            return;
        }

        setReplayingId(item.id);
        void postJson(`/api/cards/${item.run_card_id}/replay`)
            .then((response) => {
                if (response.ok) {
                    router.reload({ only: ['pendingReveal'] });
                }
            })
            .catch(() => undefined)
            .finally(() => setReplayingId(null));
    };

    const unread = items.filter((item) => !isRead(item)).length;

    return (
        <>
            <Head title="Inbox" />
            <PageContainer>
                <PageHero
                    eyebrow={
                        unread > 0
                            ? `Inbox · ${unread} unread on this page`
                            : 'Inbox'
                    }
                >
                    Everything I told you,
                    <br />
                    <em className="italic text-ink-2">still here.</em>
                </PageHero>

                {items.length === 0 ? (
                    <EmptyPanel
                        pose="reading"
                        title="Nothing here yet."
                        body="Every run, recap, and unlock lands here on its own. Nothing for you to do."
                        className="mt-8"
                    />
                ) : (
                    <motion.div
                        variants={staggerContainer}
                        initial="hidden"
                        animate="visible"
                        className="mt-8 flex flex-col gap-2.5"
                    >
                        {items.map((item) => (
                            <motion.div
                                key={item.id}
                                id={`inbox-item-${item.id}`}
                                variants={fadeInUp}
                            >
                                <InboxRow
                                    item={item}
                                    read={isRead(item)}
                                    focused={item.id === focusId}
                                    replaying={replayingId === item.id}
                                    onReplay={replay}
                                    onOpen={markRead}
                                />
                            </motion.div>
                        ))}
                    </motion.div>
                )}

                {notifications.last_page > 1 && (
                    <nav
                        aria-label="Inbox pages"
                        className="mt-6 flex items-center justify-between gap-3"
                    >
                        {notifications.current_page > 1 ? (
                            <PillLink
                                href={`/inbox?page=${notifications.current_page - 1}`}
                                tone="outline"
                                size="sm"
                            >
                                Newer
                            </PillLink>
                        ) : (
                            <span />
                        )}
                        <span className="font-mono text-xs tabular-nums text-ink-3">
                            Page {notifications.current_page} of{' '}
                            {notifications.last_page}
                        </span>
                        {notifications.current_page <
                        notifications.last_page ? (
                            <PillLink
                                href={`/inbox?page=${notifications.current_page + 1}`}
                                tone="outline"
                                size="sm"
                            >
                                Older
                            </PillLink>
                        ) : (
                            <span />
                        )}
                    </nav>
                )}
            </PageContainer>

            <AccessoryUnlockModal
                unlock={unlockReplay}
                onClose={() => setUnlockReplay(null)}
            />
        </>
    );
}

Inbox.layout = appLayout;
