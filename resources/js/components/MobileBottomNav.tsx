import type { MouseEvent } from 'react';

import { Link, router, usePage } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';

import type { TabId } from '@/lib/nav';
import type { SharedProps } from '@/types/inertia';

import { cn } from '@/lib/cn';
import { defaultTabHrefFor, ITEMS, navTabFor } from '@/lib/nav';
import {
    clearTabMemory,
    parseTabMemory,
    rememberTabLocation,
    subscribeToTabMemory,
    tabMemorySnapshot,
} from '@/lib/navigationMemory';
import { useTodayIso } from '@/lib/pace';
import {
    armPendingTab,
    pendingTabSnapshot,
    subscribePendingTab,
} from '@/lib/pendingTab';

// Tapping the active tab scrolls to top instead of a full Inertia round-trip to the same page.
function scrollToTop(event: MouseEvent<Element>) {
    event.preventDefault();
    const reduced = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;
    window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
}

function handleActiveTabClick(
    event: MouseEvent<Element>,
    tab: TabId,
    component: string,
    currentUrl: string,
    today: string,
    planSelectedDay: string | null,
) {
    if (window.scrollY > 0) {
        rememberTabLocation(tab, currentUrl, 0);
        scrollToTop(event);
        return;
    }

    event.preventDefault();
    const href = defaultTabHrefFor(tab, currentUrl, today);
    const needsReset =
        currentUrl !== href || (tab === 'plan' && planSelectedDay !== null);

    if (needsReset) {
        if (currentUrl.split('?')[0] !== href.split('?')[0]) {
            armPendingTab(tab, component);
        }
        router.visit(href, { replace: true, preserveState: false });
        clearTabMemory(tab);
    }
}

/**
 * Floating frosted-glass pill, per the prototype's AppBottomNav, centred on the
 * content column at both steps rather than spanning the viewport.
 * `bottom-[max(...)]` keeps it clear of the home-indicator area.
 *
 * The pill lights the tapped tab immediately and reconciles on Inertia's
 * `finish`, so a cancelled or failed visit falls back to the page the app is
 * actually on.
 *
 * Today carries a decorative dot while the inbox has unread rows; the bell is
 * the labelled control, so the dot is a reason to look up, not a second way in.
 * It borrows the unread dot's token from `InboxRow`; the ring keeps it off the
 * lime the active tab tints its own icon with.
 */
export default function MobileBottomNav() {
    const { component, props, url } = usePage<SharedProps>();
    const current = navTabFor(component);
    const hasUnread = (props.unreadNotifications ?? 0) > 0;
    const today = useTodayIso();
    const tabs = parseTabMemory(
        useSyncExternalStore(
            subscribeToTabMemory,
            tabMemorySnapshot,
            () => null,
        ),
    );
    const planSelectedDay = tabs.plan?.selectedDay ?? null;
    const pending = useSyncExternalStore(
        subscribePendingTab,
        pendingTabSnapshot,
        () => null,
    );

    const active = pending === null ? current : pending.tab;
    if (active === null) {
        return null;
    }

    return (
        <div className="pointer-events-none fixed inset-x-0 bottom-[max(0.875rem,calc(env(safe-area-inset-bottom)+0.25rem))] z-30 pl-[max(0.875rem,env(safe-area-inset-left))] pr-[max(0.875rem,env(safe-area-inset-right))]">
            <nav
                aria-label="Primary"
                className="pointer-events-auto mx-auto flex min-[900px]:max-w-column gap-1 min-[1280px]:max-w-column-wide rounded-full border border-foreground/20 bg-card/60 p-1.5 shadow-e2 backdrop-blur-xl backdrop-saturate-150"
            >
                {ITEMS.map((item) => {
                    const isCurrent = current === item.id;
                    const isActive = active === item.id;
                    const TabIcon = item.icon;
                    return (
                        <Link
                            key={item.id}
                            href={tabs[item.id]?.href ?? item.href}
                            aria-current={isCurrent ? 'page' : undefined}
                            onClick={
                                isCurrent
                                    ? (event) =>
                                          handleActiveTabClick(
                                              event,
                                              item.id,
                                              component,
                                              url,
                                              today,
                                              planSelectedDay,
                                          )
                                    : () => armPendingTab(item.id, component)
                            }
                            className={cn(
                                'pressable focus-ring flex flex-1 items-center justify-center gap-1.5 rounded-full py-2.5 text-text-3 no-underline transition-[flex-grow,background-color,color] duration-150',
                                isActive &&
                                    'grow-[1.6] bg-gradient-to-br from-horizon/34 to-horizon/14 text-icon-accent shadow-glow',
                            )}
                        >
                            <span className="relative block">
                                <span
                                    key={isActive ? 'active' : 'idle'}
                                    className={cn(
                                        'block',
                                        isActive && 'pop-once',
                                    )}
                                >
                                    <TabIcon
                                        className={cn(
                                            'transition-[width,height] duration-150',
                                            isActive ? 'size-5' : 'size-4.5',
                                        )}
                                        aria-hidden
                                    />
                                </span>
                                {item.id === 'today' && hasUnread && (
                                    <span
                                        data-testid="unread-dot"
                                        aria-hidden
                                        className="absolute -right-1 -top-0.5 size-1.5 rounded-full bg-icon-accent ring-2 ring-card"
                                    />
                                )}
                            </span>
                            <span
                                className={cn(
                                    'overflow-hidden text-label-micro font-extrabold tracking-[.05em] transition-[max-width,opacity] duration-200',
                                    isActive
                                        ? 'max-w-[60px] opacity-100'
                                        : 'max-w-0 opacity-0',
                                )}
                            >
                                {item.label}
                            </span>
                        </Link>
                    );
                })}
            </nav>
        </div>
    );
}
