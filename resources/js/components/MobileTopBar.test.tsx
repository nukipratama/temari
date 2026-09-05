import { act, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import MobileTopBar from './MobileTopBar';

afterEach(() => {
    window.scrollY = 0;
});

describe('MobileTopBar', () => {
    it('renders the brand mark link to home', () => {
        render(<MobileTopBar />);
        expect(screen.getByLabelText('Home')).toHaveAttribute('href', '/');
    });

    it.each([
        ['Runs/Show', '/history', 'History'],
        ['Inbox', '/', 'Today'],
        ['Profile', '/', 'Today'],
        ['Settings/Index', '/profile', 'Profile'],
        ['Collection/Accessories', '/', 'Today'],
    ])(
        'replaces the brand mark with a back button on %s',
        (component, href, label) => {
            setMockPage({}, '/x', component);
            render(<MobileTopBar />);

            const back = screen.getByLabelText(`Back to ${label}`);
            expect(back).toHaveAttribute('href', href);
            expect(screen.queryByLabelText('Home')).not.toBeInTheDocument();
        },
    );

    it.each(['Home', 'History', 'Trends', 'Plan', 'Race'])(
        'keeps the brand mark and shows no back button on %s',
        (component) => {
            setMockPage({}, '/x', component);
            render(<MobileTopBar />);

            expect(screen.getByLabelText('Home')).toBeInTheDocument();
            expect(screen.queryByLabelText(/^Back to/)).not.toBeInTheDocument();
        },
    );

    it('carries the gear to Settings on Profile, as the prototype does', () => {
        setMockPage({ auth: { user: makeUser() } }, '/profile', 'Profile');
        render(<MobileTopBar />);
        expect(screen.getByLabelText('Settings')).toHaveAttribute(
            'href',
            '/settings',
        );
        expect(screen.getByLabelText('Inbox')).toBeInTheDocument();
    });

    it('keeps the bell but drops the gear on Settings', () => {
        setMockPage(
            { auth: { user: makeUser() } },
            '/settings',
            'Settings/Index',
        );
        render(<MobileTopBar />);
        expect(screen.getByLabelText('Inbox')).toBeInTheDocument();
        expect(screen.queryByLabelText('Settings')).not.toBeInTheDocument();
    });

    it.each(['Runs/Show', 'Inbox'])(
        'leaves %s with the back chevron alone, no trailing controls',
        (component) => {
            setMockPage({ auth: { user: makeUser() } }, '/x', component);
            render(<MobileTopBar />);
            expect(screen.queryByLabelText('Inbox')).not.toBeInTheDocument();
            expect(screen.queryByLabelText('Settings')).not.toBeInTheDocument();
            expect(
                screen.queryByLabelText(/'s profile/),
            ).not.toBeInTheDocument();
        },
    );

    it('drops the Strava sync badge on a pushed screen', () => {
        setMockPage({ auth: { user: null }, stravaSync: null }, '/x', 'Inbox');
        render(<MobileTopBar />);
        expect(
            screen.queryByLabelText('Strava not connected'),
        ).not.toBeInTheDocument();
    });

    it('points back at a real url rather than relying on history', () => {
        setMockPage({}, '/activities/123', 'Runs/Show');
        render(<MobileTopBar />);
        expect(
            screen.getByLabelText('Back to History').getAttribute('href'),
        ).toBe('/history');
    });

    it('shows the avatar link to Profile when a user is signed in', () => {
        setMockPage({ auth: { user: makeUser({ name: 'Ada Lovelace' }) } });
        render(<MobileTopBar />);
        expect(screen.getByLabelText("Ada Lovelace's profile")).toHaveAttribute(
            'href',
            '/profile',
        );
    });

    it('omits the avatar link when there is no signed-in user', () => {
        setMockPage({ auth: { user: null } });
        render(<MobileTopBar />);
        expect(screen.queryByLabelText(/'s profile/)).not.toBeInTheDocument();
    });

    it('carries no Strava sync readout, which the prototype does not draw', () => {
        setMockPage({ auth: { user: null }, stravaSync: null });
        render(<MobileTopBar />);
        expect(screen.queryByLabelText(/^Strava/)).not.toBeInTheDocument();
    });

    it('pads the top by the safe-area inset so content clears the notch', () => {
        const { container } = render(<MobileTopBar />);
        expect(container.querySelector('header')).toHaveClass(
            'pt-[max(1rem,env(safe-area-inset-top))]',
        );
    });

    it('stays with the reader rather than sitting in normal flow', () => {
        const { container } = render(<MobileTopBar />);
        expect(container.querySelector('header')).toHaveClass('fixed', 'top-0');
    });

    it('paints the ground colour so content cannot scroll between the chips', () => {
        const { container } = render(<MobileTopBar />);
        const header = container.querySelector('header')!;
        expect(header).toHaveClass('bg-background');
        expect(screen.getByLabelText('Home')).toHaveClass(
            'bg-muted/70',
            'backdrop-blur-md',
        );

        act(() => {
            window.scrollY = 120;
            window.dispatchEvent(new Event('scroll'));
        });

        // Unconditional, not scroll-driven: bg-background is what the page is
        // already painting behind the bar, so it reads as transparent at rest.
        expect(header).toHaveClass('bg-background');
    });

    it('wraps the notification bell and avatar in a chip, matching the wordmark chip', () => {
        setMockPage({ auth: { user: makeUser({ name: 'Ada Lovelace' }) } });
        render(<MobileTopBar />);
        expect(screen.getByLabelText('Inbox').closest('span')).toHaveClass(
            'bg-muted/70',
            'backdrop-blur-md',
        );
        expect(
            screen.getByLabelText("Ada Lovelace's profile").closest('span'),
        ).toHaveClass('bg-muted/70', 'backdrop-blur-md');
    });

    it('wraps the back button in the same chip treatment as the wordmark', () => {
        setMockPage({}, '/activities/123', 'Runs/Show');
        render(<MobileTopBar />);
        expect(screen.getByLabelText('Back to History')).toHaveClass(
            'bg-muted/70',
            'backdrop-blur-md',
        );
    });
});
