import type { MotionConfigProps } from 'framer-motion';

import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import AppShell from './AppShell';

// Spy on MotionConfig so we can assert the app tree is wrapped in it with
// reducedMotion="user" (it renders no DOM of its own, so we can't query it).
const motionConfigSpy = vi.fn();
vi.mock('framer-motion', async (importOriginal) => {
    const actual = await importOriginal<typeof import('framer-motion')>();
    return {
        ...actual,
        MotionConfig: (props: MotionConfigProps) => {
            motionConfigSpy(props.reducedMotion);
            return actual.MotionConfig(props);
        },
    };
});

const andiUser = { id: 1, name: 'Andi', first_name: 'Andi', avatar_url: null };

describe('AppShell', () => {
    afterEach(() => {
        motionConfigSpy.mockClear();
    });

    it('wraps the app tree in MotionConfig reducedMotion="user"', () => {
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
        expect(motionConfigSpy).toHaveBeenCalledWith('user');
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
        // <main> keeps bottom clearance for the floating bottom nav.
        const main = document.getElementById('main-content');
        expect(main?.className).toContain('pb-28');
    });

    it('mounts the route progress bar as shell chrome, idle by default', () => {
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
        expect(screen.getByTestId('route-progress-bar')).toHaveAttribute(
            'data-phase',
            'idle',
        );
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
        expect(screen.getByText(/Reconnect Strava/)).toBeInTheDocument();
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
            '/accessories',
            'Collection/Accessories',
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
        expect(main).toHaveClass('outline-none', 'pb-28');
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
        setMockPage(
            { auth: { user: makeUser() } },
            '/accessories',
            'Collection/Accessories',
        );
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
