import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/inertia';

/**
 * Whether Temari can reach the user on *any* notification channel — Telegram
 * connected or web push subscribed. The manual send is channel-neutral (the
 * server fans out to every wired channel), so the UI gates on this rather than
 * on Telegram alone; a push-only user would otherwise see a dead button.
 */
export function useNotificationsReachable(): boolean {
    const { telegramConnected, webPushSubscribed } = usePage<SharedProps>().props;

    return (telegramConnected ?? false) || (webPushSubscribed ?? false);
}
