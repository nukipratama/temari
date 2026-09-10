import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

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

    // The shell owns the cross-page banners; pages no longer render them, so
    // this is the only place their mounting is asserted.
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
});
