import type { GlobalEvent, PendingVisit } from '@inertiajs/core';

import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    clearTabMemory,
    parseTabMemory,
    rememberPlanSelectedDay,
    tabMemorySnapshot,
    writeTabMemory,
} from '@/lib/navigationMemory';
import { pendingTabSnapshot, trackTabVisits } from '@/lib/pendingTab';
import { setMockPage } from '@/test/setup';

import MobileBottomNav from './MobileBottomNav';

vi.mock('@/lib/navigationMemory', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/lib/navigationMemory')>()),
    readTabMemory: () => null,
    readPlanSelectedDay: () => null,
}));

function storedTab(tab: 'history' | 'plan') {
    return parseTabMemory(tabMemorySnapshot())[tab] ?? null;
}

function routerHandler<T extends 'start' | 'finish'>(name: T) {
    const call = [...vi.mocked(router.on).mock.calls]
        .reverse()
        .find(([event]) => event === name);
    if (!call) {
        throw new Error(`router.on was never called for "${name}"`);
    }
    return call[1] as (event: GlobalEvent<T>) => void;
}

function tabVisit(href: string) {
    return {
        url: new URL(href, 'http://localhost'),
        method: 'get',
        async: false,
        prefetch: false,
    } as PendingVisit;
}

function fireStart(visit: PendingVisit) {
    routerHandler('start')({ detail: { visit } } as GlobalEvent<'start'>);
}

function fireFinish(visit: PendingVisit) {
    act(() => {
        routerHandler('finish')({ detail: { visit } } as GlobalEvent<'finish'>);
    });
}

function tap(label: string, href: string) {
    const visit = tabVisit(href);
    act(() => {
        fireEvent.click(screen.getByText(label).closest('a')!);
        fireStart(visit);
    });
    return visit;
}

describe('MobileBottomNav', () => {
    let untrack: () => void;

    beforeEach(() => {
        vi.mocked(router.on).mockClear();
        vi.mocked(router.visit).mockClear();
        window.sessionStorage.clear();
        window.scrollY = 0;
        untrack = trackTabVisits();
    });

    afterEach(() => {
        const left = pendingTabSnapshot();
        if (left !== null) {
            fireFinish(left.visit);
        }
        untrack();
        window.sessionStorage.clear();
        window.scrollY = 0;
    });

    it('renders all four primary tabs with their labels', () => {
        render(<MobileBottomNav />);
        expect(screen.getByText('Today')).toBeInTheDocument();
        expect(screen.getByText('Plan')).toBeInTheDocument();
        expect(screen.getByText('Trends')).toBeInTheDocument();
        expect(screen.getByText('History')).toBeInTheDocument();
    });

    it('marks the tab for the current page component as active', () => {
        setMockPage({}, '/history', 'History');
        render(<MobileBottomNav />);
        const link = screen.getByText('History').closest('a')!;
        expect(link).toHaveAttribute('aria-current', 'page');
        expect(screen.getByText('Today').closest('a')).not.toHaveAttribute(
            'aria-current',
        );
    });

    it('links each tab to its target path', () => {
        render(<MobileBottomNav />);
        expect(screen.getByText('Today').closest('a')).toHaveAttribute(
            'href',
            '/',
        );
        expect(screen.getByText('Plan').closest('a')).toHaveAttribute(
            'href',
            '/plan',
        );
        expect(screen.getByText('Trends').closest('a')).toHaveAttribute(
            'href',
            '/trends',
        );
        expect(screen.getByText('History').closest('a')).toHaveAttribute(
            'href',
            '/history',
        );
    });

    it('uses the saved route for the inactive tab', () => {
        writeTabMemory('history', {
            href: '/history?view=calendar&month=2026-06',
            scrollY: 440,
        });
        setMockPage({}, '/', 'Home');
        render(<MobileBottomNav />);

        expect(screen.getByText('History').closest('a')).toHaveAttribute(
            'href',
            '/history?view=calendar&month=2026-06',
        );
    });

    it('derives tab links from the subscribed snapshot, not a storage read in render', () => {
        writeTabMemory('history', {
            href: '/history?view=calendar&month=2026-06',
            scrollY: 440,
        });
        setMockPage({}, '/', 'Home');
        render(<MobileBottomNav />);

        expect(screen.getByText('History').closest('a')).toHaveAttribute(
            'href',
            '/history?view=calendar&month=2026-06',
        );
    });

    it('updates a tab link when its memory changes after mount', () => {
        setMockPage({}, '/', 'Home');
        render(<MobileBottomNav />);
        expect(screen.getByText('History').closest('a')).toHaveAttribute(
            'href',
            '/history',
        );

        act(() => {
            writeTabMemory('history', {
                href: '/history?view=calendar&month=2026-06',
                scrollY: 0,
            });
        });

        expect(screen.getByText('History').closest('a')).toHaveAttribute(
            'href',
            '/history?view=calendar&month=2026-06',
        );

        act(() => {
            clearTabMemory('history');
        });

        expect(screen.getByText('History').closest('a')).toHaveAttribute(
            'href',
            '/history',
        );
    });

    it('resets a Plan day selected after mount when its tab is tapped at top', () => {
        setMockPage({}, '/plan', 'Plan');
        render(<MobileBottomNav />);

        act(() => {
            rememberPlanSelectedDay('2026-06-16');
        });
        fireEvent.click(screen.getByText('Plan').closest('a')!);

        expect(router.visit).toHaveBeenCalledWith('/plan', {
            replace: true,
            preserveState: false,
        });
    });

    // The floating pill grows and gets a lime gradient fill for the active
    // tab (per the prototype's AppBottomNav); inactive tabs stay a plain
    // muted tone rather than the old bar's on-sky treatment.
    it('grows and tints the active tab, leaving inactive tabs muted', () => {
        setMockPage({}, '/history', 'History');
        render(<MobileBottomNav />);
        expect(screen.getByText('History').closest('a')).toHaveClass(
            'text-icon-accent',
            'grow-[1.6]',
        );
        expect(screen.getByText('Today').closest('a')).toHaveClass(
            'text-text-3',
        );
        expect(screen.getByText('Today').closest('a')).not.toHaveClass(
            'grow-[1.6]',
        );
    });

    it('sizes the active and idle tab icons in rem', () => {
        setMockPage({}, '/history', 'History');
        render(<MobileBottomNav />);
        expect(
            screen.getByText('History').closest('a')?.querySelector('svg'),
        ).toHaveClass('size-5');
        expect(
            screen.getByText('Today').closest('a')?.querySelector('svg'),
        ).toHaveClass('size-4.5');
    });

    it('scrolls to top instead of navigating when the active tab is tapped', () => {
        const scrollTo = vi.fn();
        vi.stubGlobal('scrollTo', scrollTo);
        vi.stubGlobal(
            'matchMedia',
            vi.fn(() => ({ matches: false })),
        );
        setMockPage({}, '/history', 'History');
        window.scrollY = 180;
        render(<MobileBottomNav />);

        const link = screen.getByText('History').closest('a')!;
        const event = new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
        });
        link.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
        expect(router.visit).not.toHaveBeenCalled();
        expect(storedTab('history')?.scrollY).toBe(0);
    });

    it('leaves an inactive tab to navigate normally', () => {
        const scrollTo = vi.fn();
        vi.stubGlobal('scrollTo', scrollTo);
        setMockPage({}, '/history', 'History');
        render(<MobileBottomNav />);

        const link = screen.getByText('Today').closest('a')!;
        const event = new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
        });
        link.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expect(scrollTo).not.toHaveBeenCalled();
    });

    it('jumps without animating when the user asks for reduced motion', () => {
        const scrollTo = vi.fn();
        vi.stubGlobal('scrollTo', scrollTo);
        vi.stubGlobal(
            'matchMedia',
            vi.fn((query: string) => ({
                matches: query.includes('prefers-reduced-motion'),
                media: query,
                addEventListener: vi.fn(),
                removeEventListener: vi.fn(),
            })),
        );
        setMockPage({}, '/history', 'History');
        window.scrollY = 180;
        render(<MobileBottomNav />);

        screen
            .getByText('History')
            .closest('a')!
            .dispatchEvent(
                new MouseEvent('click', { bubbles: true, cancelable: true }),
            );

        expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'auto' });
    });

    it("resets the active calendar tab to the server date's month when already at top", () => {
        writeTabMemory('history', {
            href: '/history?view=calendar&month=2026-06',
            scrollY: 240,
        });
        setMockPage(
            { today: '2026-09-30' },
            '/history?view=calendar&month=2026-06',
            'History',
        );
        render(<MobileBottomNav />);

        fireEvent.click(screen.getByText('History').closest('a')!);

        expect(router.visit).toHaveBeenCalledWith(
            '/history?view=calendar&month=2026-09',
            { replace: true, preserveState: false },
        );
        expect(storedTab('history')).toBeNull();
    });

    it('resets a selected Plan day when its active tab is tapped at top', () => {
        writeTabMemory('plan', {
            href: '/plan',
            scrollY: 0,
            selectedDay: '2026-06-16',
        });
        setMockPage({}, '/plan', 'Plan');
        render(<MobileBottomNav />);

        fireEvent.click(screen.getByText('Plan').closest('a')!);

        expect(router.visit).toHaveBeenCalledWith('/plan', {
            replace: true,
            preserveState: false,
        });
        expect(storedTab('plan')?.selectedDay).toBeUndefined();
    });

    it('lights the plan tab on Race, a sub-page of Plan', () => {
        setMockPage({}, '/race', 'Race');
        render(<MobileBottomNav />);
        expect(screen.getByText('Plan').closest('a')).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('renders nothing on a pushed screen', () => {
        setMockPage({}, '/inbox', 'Inbox');
        const { container } = render(<MobileBottomNav />);
        expect(container).toBeEmptyDOMElement();
    });

    it('lights the tapped tab before the server has answered', () => {
        setMockPage({}, '/history', 'History');
        render(<MobileBottomNav />);

        tap('Today', '/');

        expect(screen.getByText('Today').closest('a')).toHaveClass(
            'grow-[1.6]',
            'text-icon-accent',
        );
        expect(screen.getByText('History').closest('a')).not.toHaveClass(
            'grow-[1.6]',
        );
    });

    // The highlight is optimistic; `aria-current` is not, so a screen reader is
    // never told it is on a page the app has not reached.
    it('leaves aria-current on the page actually being shown', () => {
        setMockPage({}, '/history', 'History');
        render(<MobileBottomNav />);

        tap('Today', '/');

        expect(screen.getByText('History').closest('a')).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.getByText('Today').closest('a')).not.toHaveAttribute(
            'aria-current',
        );
    });

    it('hands the highlight back to the current page when a visit never arrives', () => {
        setMockPage({}, '/history', 'History');
        render(<MobileBottomNav />);

        const visit = tap('Today', '/');
        fireFinish(visit);

        expect(screen.getByText('History').closest('a')).toHaveClass(
            'grow-[1.6]',
        );
        expect(screen.getByText('Today').closest('a')).not.toHaveClass(
            'grow-[1.6]',
        );
    });

    it('keeps the second tap highlighted when the first tap is interrupted', () => {
        setMockPage({}, '/history', 'History');
        render(<MobileBottomNav />);

        const first = tap('Today', '/');
        const second = tabVisit('/plan');
        act(() => {
            fireEvent.click(screen.getByText('Plan').closest('a')!);
            routerHandler('finish')({
                detail: { visit: first },
            } as GlobalEvent<'finish'>);
            fireStart(second);
        });

        expect(screen.getByText('Plan').closest('a')).toHaveClass('grow-[1.6]');
        expect(screen.getByText('History').closest('a')).not.toHaveClass(
            'grow-[1.6]',
        );

        fireFinish(second);

        expect(screen.getByText('History').closest('a')).toHaveClass(
            'grow-[1.6]',
        );
        expect(screen.getByText('Plan').closest('a')).not.toHaveClass(
            'grow-[1.6]',
        );
    });

    // The bell in the top bar is the labelled, actionable control; this is a
    // reason to look up, so it carries no count and is not announced.
    it('lights nothing new when a tap is not followed by its visit', () => {
        setMockPage({}, '/history', 'History');
        render(<MobileBottomNav />);

        fireEvent.click(screen.getByText('Today').closest('a')!);

        expect(screen.getByText('Today').closest('a')).not.toHaveClass(
            'grow-[1.6]',
        );
    });

    it('dots the today tab while the inbox has unread rows', () => {
        setMockPage({ unreadNotifications: 3 }, '/history', 'History');
        render(<MobileBottomNav />);

        const dot = screen.getByTestId('unread-dot');
        expect(screen.getByText('Today').closest('a')).toContainElement(dot);
        expect(dot).toHaveAttribute('aria-hidden', 'true');
        expect(dot).not.toHaveTextContent('3');
    });

    it('leaves the tabs undotted when nothing is unread', () => {
        setMockPage({ unreadNotifications: 0 }, '/history', 'History');
        render(<MobileBottomNav />);

        expect(screen.queryByTestId('unread-dot')).not.toBeInTheDocument();
    });

    it('keeps the pill clear of a landscape notch on both sides', () => {
        setMockPage({}, '/', 'Home');
        const { container } = render(<MobileBottomNav />);

        expect(container.firstElementChild).toHaveClass(
            'pl-[max(0.875rem,env(safe-area-inset-left))]',
            'pr-[max(0.875rem,env(safe-area-inset-right))]',
        );
    });

    it('centres the pill on the content column rather than spanning the viewport', () => {
        setMockPage({}, '/', 'Home');
        render(<MobileBottomNav />);
        expect(screen.getByRole('navigation')).toHaveClass(
            'mx-auto',
            'min-[900px]:max-w-column',
        );
    });

    it('tracks the content column at its wide step too', () => {
        setMockPage({}, '/', 'Home');
        render(<MobileBottomNav />);
        // A pill narrower than the content above it reads as misaligned; P32's
        // objection was to a full-bleed track, which 1040 still is not.
        expect(screen.getByRole('navigation')).toHaveClass(
            'min-[1280px]:max-w-column-wide',
        );
    });
});

describe('MobileBottomNav column cap', () => {
    it('caps the column only from 900px up', () => {
        const { container } = render(<MobileBottomNav />);
        const box = container.querySelector('[class*="max-w-column"]')!;
        expect(box.classList.contains('max-w-column')).toBe(false);
        expect(box.classList.contains('min-[900px]:max-w-column')).toBe(true);
    });
});
