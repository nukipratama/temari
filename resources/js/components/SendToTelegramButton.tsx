import { Icon } from '@iconify/react';
import PillButton from '@/components/ui/PillButton';
import { usePendingPost } from '@/hooks/usePendingPost';

/**
 * The manual "Kirim ke Telegram" pill shared by run/weekly/monthly recap
 * surfaces: force-pushes the Done narration at `url` and shows a spinner while
 * in flight. Callers gate rendering on `telegramConnected`.
 */
export default function SendToTelegramButton({ url }: Readonly<{ url: string }>) {
    const [sending, send] = usePendingPost(url, { preserveScroll: true });

    return (
        <PillButton tone="outline" size="sm" disabled={sending} className="disabled:opacity-60 disabled:cursor-not-allowed" onClick={send}>
            <Icon
                icon={sending ? 'mdi:loading' : 'mdi:send'}
                width={15}
                height={15}
                className={sending ? 'animate-spin' : undefined}
                aria-hidden
            />
            {sending ? 'Lagi ngirim…' : 'Kirim ke Telegram'}
        </PillButton>
    );
}
