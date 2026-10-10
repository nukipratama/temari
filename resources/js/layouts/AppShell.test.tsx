import type { GlobalEvent, PendingVisit } from '@inertiajs/core';

import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pendingTabSnapshot } from '@/lib/pendingTab';
import { makeUser, setMockPage } from '@/test/setup';

import AppShell from './AppShell';

const andiUser = { id: 1, name: 'Andi', first_name: 'Andi', avatar_url: null };

describe('AppShell', () => {
    it('insets the whole shell past a landscape notch', () => {
        setMockPage({
            auth: { user: andiUser },
            flash: {},
            demoLoginEnabled: false,
        });
        const { container } = render(
            <AppShell>
                <p>x</p>
            </AppShell>,
        );

        expect(container.querySelector('.min-h-screen')).toHaveClass(
            'pl-[env(safe-area-inset-left)]',
            'pr-[env(safe-area-inset-right)]',
        );
    });

    it('renders the 4 primary tabs + children by default', () => {
        setMockPage({
            auth: { user: andiUser },
            flash: {},
            demoLoginEnabled: false,
        });
        render(
            <AppShell>
                <p>child content</p>
            </AppShell>,
        );
        expect(screen.getByText('child content')).toBeInTheDocument();
        ['Today', 'Plan', 'Trends', 'History'].forEach((label) => {
            expect(screen.getAllByText(label).length).toBeGreaterThan(0);
        });
        // <main> keeps bottom clearance for the floating bottom nav, above the
        // home-indicator inset rather than flat against it.
        const main = document.getElementById('main-content');
        expect(main?.className).toContain(
            'pb-[calc(7rem+env(safe-area-inset-bottom))]',
        );
    });

    it('renders exactly one main landmark around the children', () => {
        setMockPage({
            auth: { user: andiUser },
            flash: {},
            demoLoginEnabled: false,
        });
        render(
            <AppShell>
                <p>child content</p>
            </AppShell>,
        );

        const main = screen.getByRole('main');
        expect(screen.getAllByRole('main')).toHaveLength(1);
        expect(main).toContainElement(screen.getByText('child content'));
    });

    it('draws no progress bar — the shell paints at once and the swap cross-fades', () => {
        setMockPage({
            auth: { user: andiUser },
            flash: {},
            demoLoginEnabled: false,
        });
        render(
            <AppShell>
                <p>child content</p>
            </AppShell>,
        );
        expect(screen.queryByTestId('route-progress-bar')).toBeNull();
    });

    // The shell owns the cross-page banners except the AI pause banner, which
    // the narration pages mount; this is the only place the rest are asserted.
    it('mounts the Strava zone reconnect banner as shell chrome', () => {
        setMockPage({
            auth: { user: andiUser },
            flash: {},
            demoLoginEnabled: false,
            stravaZoneScopeMissing: true,
        });
        render(
            <AppShell>
                <p>child content</p>
            </AppShell>,
        );
        expect(
            screen.getByText(/Strava only shares your HR zones/),
        ).toBeInTheDocument();
    });

    it('leaves the AI pause banner to the pages that render narration', () => {
        setMockPage({
            auth: { user: andiUser },
            flash: {},
            demoLoginEnabled: false,
            aiPaused: true,
        });
        render(
            <AppShell>
                <p>child content</p>
            </AppShell>,
        );
        expect(
            screen.queryByText(/catching her breath/),
        ).not.toBeInTheDocument();
    });

    it('mounts the flash notice as shell chrome', () => {
        setMockPage({
            auth: { user: andiUser },
            flash: {
                info: "The pull from Strava is paused for a bit. It'll resume automatically.",
            },
            demoLoginEnabled: false,
        });
        render(
            <AppShell>
                <p>child content</p>
            </AppShell>,
        );
        expect(
            screen.getByText(/The pull from Strava is paused for a bit/),
        ).toBeInTheDocument();
    });

    // The content region used to be keyed on the Inertia component name, which
    // tore down and rebuilt the whole subtree on every visit and replayed an
    // enter animation starting at opacity 0 — so a navigation read as
    // "old page -> blank -> fade in". Both are gone; this pins that.
    it('does not remount the content region when the page component changes', () => {
        setMockPage(
            { auth: { user: andiUser }, flash: {}, demoLoginEnabled: false },
            '/',
            'Today',
        );
        const { rerender } = render(
            <AppShell>
                <p>body</p>
            </AppShell>,
        );
        const before = document.getElementById('main-content');

        setMockPage(
            { auth: { user: andiUser }, flash: {}, demoLoginEnabled: false },
            '/inbox',
            'Inbox',
        );
        rerender(
            <AppShell>
                <p>body</p>
            </AppShell>,
        );

        expect(document.getElementById('main-content')).toBe(before);
    });

    it('carries no enter animation that would blank the content first', () => {
        setMockPage({
            auth: { user: andiUser },
            flash: {},
            demoLoginEnabled: false,
        });
        render(
            <AppShell>
                <p>body</p>
            </AppShell>,
        );

        const main = document.getElementById('main-content');
        expect(main).toHaveClass(
            'outline-none',
            'pb-[calc(7rem+env(safe-area-inset-bottom))]',
        );
        // Exactly those two: an enter animation would have to add a class
        // here, and starting one at opacity 0 is what read as "old page ->
        // blank -> fade in".
        expect(main?.className.split(' ')).toHaveLength(2);
    });

    it('keeps the content region mounted across a partial reload of the same page', () => {
        setMockPage(
            { auth: { user: andiUser }, flash: {}, demoLoginEnabled: false },
            '/activities',
            'Activities/Feed',
        );
        const { rerender } = render(
            <AppShell>
                <p>body</p>
            </AppShell>,
        );
        const before = document.getElementById('main-content');

        // Same component, new query string — a filter/`only:` refresh.
        setMockPage(
            { auth: { user: andiUser }, flash: {}, demoLoginEnabled: false },
            '/activities?range=8w',
            'Activities/Feed',
        );
        rerender(
            <AppShell>
                <p>body</p>
            </AppShell>,
        );

        expect(document.getElementById('main-content')).toBe(before);
    });

    it('shows the mobile top bar on every page', () => {
        setMockPage({ auth: { user: makeUser() } }, '/inbox', 'Inbox');
        render(<AppShell>content</AppShell>);
        // Scoped by testid, not by tag: TopNav is also a <header> and stays in
        // the DOM on mobile, hidden by CSS alone.
        expect(screen.getByTestId('mobile-top-bar')).toBeInTheDocument();
    });

    // Without tabindex the fragment target is unfocusable, so activating the
    // skip link scrolls but leaves focus (and the screen reader) in the header.
    it('makes the skip link target focusable', () => {
        setMockPage({
            auth: { user: andiUser },
            flash: {},
            demoLoginEnabled: false,
        });
        render(
            <AppShell>
                <p>x</p>
            </AppShell>,
        );

        const skip = screen.getByRole('link', { name: /konten|content/i });
        const target = document.getElementById('main-content');
        expect(skip).toHaveAttribute('href', '#main-content');
        expect(target).toHaveAttribute('tabindex', '-1');
    });

    it('reloads the page when the content is pulled down from the top', async () => {
        vi.mocked(router.reload).mockClear();
        vi.stubGlobal(
            'matchMedia',
            vi.fn((query: string) => ({
                matches: query.includes('coarse'),
                addEventListener: vi.fn(),
                removeEventListener: vi.fn(),
            })),
        );
        setMockPage({ auth: { user: makeUser() } }, '/inbox', 'Inbox');
        render(
            <AppShell>
                <p>child content</p>
            </AppShell>,
        );
        await act(async () => {
            await vi.dynamicImportSettled();
        });
        const child = screen.getByText('child content');

        fireEvent.touchStart(child, {
            touches: [{ identifier: 1, clientX: 10, clientY: 100 }],
        });
        fireEvent.touchMove(child, {
            touches: [{ identifier: 1, clientX: 10, clientY: 220 }],
        });
        fireEvent.touchEnd(child, { changedTouches: [] });

        expect(router.reload).toHaveBeenCalledTimes(1);
    });

    it('keeps the top bar outside the pulled content', () => {
        setMockPage({ auth: { user: makeUser() } }, '/inbox', 'Inbox');
        render(<AppShell>content</AppShell>);

        expect(
            screen
                .getByTestId('pull-to-refresh-content')
                .contains(screen.getByTestId('mobile-top-bar')),
        ).toBe(false);
    });
});

function routerHandler<T extends 'start' | 'finish'>(name: T) {
    const call = [...vi.mocked(router.on).mock.calls]
        .reverse()
        .find(([event]) => event === name);
    if (!call) {
        throw new Error(`router.on was never called for "${name}"`);
    }
    return call[1] as (event: GlobalEvent<T>) => void;
}

function pageVisit(href: string, overrides: Partial<PendingVisit> = {}) {
    return {
        url: new URL(href, 'http://localhost'),
        method: 'get',
        async: false,
        prefetch: false,
        ...overrides,
    } as PendingVisit;
}

function startVisit(visit: PendingVisit) {
    act(() => {
        routerHandler('start')({ detail: { visit } } as GlobalEvent<'start'>);
    });
}

function finishVisit(visit: PendingVisit) {
    act(() => {
        routerHandler('finish')({
            detail: { visit },
        } as GlobalEvent<'finish'>);
    });
}

function tapTab(label: string, href: string) {
    const visit = pageVisit(href);
    act(() => {
        fireEvent.click(screen.getByText(label).closest('a')!);
        routerHandler('start')({ detail: { visit } } as GlobalEvent<'start'>);
    });
    return visit;
}

function renderShell(url: string, component: string) {
    setMockPage(
        { auth: { user: andiUser }, flash: {}, demoLoginEnabled: false },
        url,
        component,
    );
    return render(
        <AppShell>
            <p>current page</p>
        </AppShell>,
    );
}

describe('AppShell tab skeleton', () => {
    beforeEach(() => {
        vi.mocked(router.on).mockClear();
        vi.stubGlobal('scrollTo', vi.fn());
    });

    afterEach(() => {
        const left = pendingTabSnapshot();
        if (left !== null) {
            finishVisit(left.visit);
        }
    });

    it.each([
        ['Today', '/', 'today'],
        ['Plan', '/plan', 'the weeks ahead.'],
        ['Trends', '/trends', 'am i getting fitter,and at what cost?'],
        ['History', '/history', 'every runhas a story.'],
    ])(
        'shows the %s skeleton in place of the page the moment its tab is tapped',
        (label, href, title) => {
            renderShell(
                label === 'Today' ? '/plan' : '/',
                label === 'Today' ? 'Plan' : 'Home',
            );

            tapTab(label, href);

            expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
                title,
            );
            expect(screen.getByText('current page')).not.toBeVisible();
            expect(document.getElementById('main-content')).toHaveAttribute(
                'aria-busy',
                'true',
            );
            expect(
                screen.getByRole('navigation', { name: 'Primary' }),
            ).toBeInTheDocument();
        },
    );

    it("draws History's calendar half when the tab returns to the calendar", () => {
        renderShell('/', 'Home');

        tapTab('History', '/history?view=calendar&month=2026-10');

        expect(screen.getByText('calendar').closest('a')).toHaveClass(
            'bg-card',
        );
    });

    it('hands the page back when the visit ends without arriving', () => {
        renderShell('/', 'Home');

        const visit = tapTab('Plan', '/plan');
        finishVisit(visit);

        expect(screen.getByText('current page')).toBeVisible();
        expect(screen.queryByRole('heading', { level: 1 })).toBeNull();
        expect(document.getElementById('main-content')).not.toHaveAttribute(
            'aria-busy',
        );
    });

    it('gives way to the destination page as soon as it renders', () => {
        const { rerender } = renderShell('/', 'Home');

        tapTab('Trends', '/trends');
        setMockPage(
            { auth: { user: andiUser }, flash: {}, demoLoginEnabled: false },
            '/trends',
            'Trends',
        );
        rerender(
            <AppShell>
                <p>trends page</p>
            </AppShell>,
        );

        expect(screen.getByText('trends page')).toBeVisible();
        expect(screen.queryByText('am i getting fitter,')).toBeNull();
    });

    it('shows no skeleton when the current tab is tapped', () => {
        renderShell('/plan', 'Plan');

        tapTab('Plan', '/plan');

        expect(screen.getByText('current page')).toBeVisible();
        expect(screen.queryByRole('heading', { level: 1 })).toBeNull();
    });

    it.each([
        [
            'a partial reload',
            pageVisit('/', { async: true, only: ['narration'] }),
        ],
        ['a full reload of the current page', pageVisit('/')],
        ['a pull-to-refresh reload', pageVisit('/', { async: true })],
    ])('shows no skeleton for %s', (_, visit) => {
        renderShell('/', 'Home');

        startVisit(visit);

        expect(screen.getByText('current page')).toBeVisible();
        expect(pendingTabSnapshot()).toBeNull();
    });

    it('shows no skeleton for a reload that starts between the tap and its visit', () => {
        renderShell('/', 'Home');

        act(() => {
            fireEvent.click(screen.getByText('Plan').closest('a')!);
        });
        startVisit(pageVisit('/', { async: true }));

        expect(screen.getByText('current page')).toBeVisible();
    });
});
