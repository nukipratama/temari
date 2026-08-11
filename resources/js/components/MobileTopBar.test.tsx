import { act, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import MobileTopBar from './MobileTopBar';

describe('MobileTopBar', () => {
    it('renders the brand mark link to home', () => {
        render(<MobileTopBar />);
        expect(screen.getByLabelText('Home')).toHaveAttribute('href', '/');
    });

    // Roots show identity, pushed screens show a way out — the native split.
    // Note the third case: /calendar, /records, /accessories and /goals resolve to a
    // tab too, but are reached through in-page tab strips, so they are siblings
    // rather than pushes and must keep the brand mark.
    it.each([
        ['Runs/Show', '/activities', 'History'],
        ['Settings/HrZones', '/settings', 'Settings'],
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

    // Settings sits in this list, not the pushed one: it is one tap from the
    // Me tab and from the avatar menu on every page, so it behaves as a root.
    it.each([
        'Today',
        'Collection/Cards',
        'Activities/Feed',
        'Profile',
        'Activities/Calendar',
        'Collection/Records',
        'Settings/Index',
    ])('keeps the brand mark and shows no back button on %s', (component) => {
        setMockPage({}, '/x', component);
        render(<MobileTopBar />);

        expect(screen.getByLabelText('Home')).toBeInTheDocument();
        expect(screen.queryByLabelText(/^Back to/)).not.toBeInTheDocument();
    });

    // A notification deep link opens the run detail cold, with nothing behind
    // it, so back has to be a real href rather than history.back().
    it('points back at a real url rather than relying on history', () => {
        setMockPage({}, '/activities/123', 'Runs/Show');
        render(<MobileTopBar />);
        expect(
            screen.getByLabelText('Back to History').getAttribute('href'),
        ).toBe('/activities');
    });

    it('shows the user menu when a user is signed in', () => {
        setMockPage({ auth: { user: makeUser({ name: 'Ada Lovelace' }) } });
        render(<MobileTopBar />);
        expect(
            screen.getByLabelText('Open menu for Ada Lovelace'),
        ).toBeInTheDocument();
    });

    it('omits the user menu when there is no signed-in user', () => {
        setMockPage({ auth: { user: null } });
        render(<MobileTopBar />);
        expect(screen.queryByLabelText(/Open menu/)).not.toBeInTheDocument();
    });

    it('renders the Strava sync badge in its disconnected state by default', () => {
        setMockPage({ auth: { user: null }, stravaSync: null });
        render(<MobileTopBar />);
        expect(
            screen.getByLabelText('Strava not connected'),
        ).toBeInTheDocument();
    });

    // Installed as a PWA the page runs edge-to-edge, so this bar has to pad
    // itself past the notch or content slides under the status bar.
    it('pads the top by the safe-area inset so content clears the notch', () => {
        const { container } = render(<MobileTopBar />);
        expect(container.querySelector('header')).toHaveClass(
            'pt-[max(0.75rem,env(safe-area-inset-top))]',
        );
    });

    it('sticks to the top so content scrolls underneath it', () => {
        const { container } = render(<MobileTopBar />);
        expect(container.querySelector('header')).toHaveClass(
            'sticky',
            'top-0',
        );
    });

    it('hides the hairline at rest and shows it once scrolled', () => {
        const { container } = render(<MobileTopBar />);
        expect(container.querySelector('header')).toHaveClass(
            'border-transparent',
        );

        act(() => {
            window.scrollY = 120;
            window.dispatchEvent(new Event('scroll'));
        });

        expect(container.querySelector('header')).toHaveClass('border-line');
        window.scrollY = 0;
    });

    it('keeps the cream ground', () => {
        const { container } = render(<MobileTopBar />);
        expect(container.querySelector('header')).toHaveClass(
            'bg-cream-deep/85',
        );
    });
});
