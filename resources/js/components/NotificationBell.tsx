import { Icon } from '@iconify/react';
import { Link, usePage } from '@inertiajs/react';

import type { SharedProps } from '@/types/inertia';

import { cn } from '@/lib/cn';

interface NotificationBellProps {
    /** Tighter hit area for the mobile top bar. Default: desktop sizing. */
    density?: 'default' | 'compact';
}

export default function NotificationBell({
    density = 'default',
}: Readonly<NotificationBellProps>) {
    const { url, props } = usePage<SharedProps>();
    const unread = props.unreadNotifications ?? 0;
    const isActive = url.split('?')[0] === '/inbox';
    const compact = density === 'compact';

    return (
        <Link
            href="/inbox"
            aria-current={isActive ? 'page' : undefined}
            aria-label={unread > 0 ? `Inbox, ${unread} unread` : 'Inbox'}
            className={cn(
                'pressable focus-ring relative inline-flex items-center justify-center rounded-full transition',
                compact ? 'h-9 w-9' : 'h-11 w-11',
                isActive
                    ? 'bg-ink/[0.06] text-ink'
                    : 'text-ink-3 hover:bg-ink/[0.04] hover:text-ink-2',
            )}
        >
            <Icon
                icon="mdi:bell-outline"
                width={compact ? 19 : 21}
                height={compact ? 19 : 21}
                aria-hidden
            />
            {unread > 0 && (
                <span
                    aria-hidden
                    className={cn(
                        'absolute right-0.5 top-0.5 inline-flex min-w-4 items-center justify-center rounded-full',
                        'bg-ember-deep px-1 font-mono text-[10px] font-bold leading-4 tabular-nums text-cream',
                    )}
                >
                    {unread > 9 ? '9+' : unread}
                </span>
            )}
        </Link>
    );
}
