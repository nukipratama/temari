import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import MobileTopBar from './MobileTopBar';

describe('MobileTopBar', () => {
    it('renders the brand mark link to home', () => {
        render(<MobileTopBar />);
        expect(screen.getByLabelText('Home')).toHaveAttribute('href', '/');
    });

    it.each([['Runs/Show', '/history', 'History']])(
        'replaces the brand mark with a back button on %s',
        (component, href, label) => {
            setMockPage({}, '/x', component);
            render(<MobileTopBar />);

            const back = screen.getByLabelText(`Back to ${label}`);
            expect(back).toHaveAttribute('href', href);
            expect(screen.queryByLabelText('Home')).not.toBeInTheDocument();
        },
    );

    it.each([
        'Today',
        'Collection/Accessories',
        'History',
        'Profile',
        'Trends',
        'Settings/Index',
        'Race',
    ])('keeps the brand mark and shows no back button on %s', (component) => {
        setMockPage({}, '/x', component);
        render(<MobileTopBar />);

        expect(screen.getByLabelText('Home')).toBeInTheDocument();
        expect(screen.queryByLabelText(/^Back to/)).not.toBeInTheDocument();
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

    it('renders the Strava sync badge in its disconnected state by default', () => {
        setMockPage({ auth: { user: null }, stravaSync: null });
        render(<MobileTopBar />);
        expect(
            screen.getByLabelText('Strava not connected'),
        ).toBeInTheDocument();
    });

    it('pads the top by the safe-area inset so content clears the notch', () => {
        const { container } = render(<MobileTopBar />);
        expect(container.querySelector('header')).toHaveClass(
            'pt-[max(1rem,env(safe-area-inset-top))]',
        );
    });

    it('floats over content rather than sitting in normal flow', () => {
        const { container } = render(<MobileTopBar />);
        expect(container.querySelector('header')).toHaveClass(
            'absolute',
            'top-0',
        );
    });

    it('carries no background of its own — only the chips inside it do', () => {
        const { container } = render(<MobileTopBar />);
        const header = container.querySelector('header')!;
        expect(header.className).not.toMatch(/\bbg-/);
        expect(screen.getByLabelText('Home')).toHaveClass('bg-muted');
    });

    it('wraps the notification bell and avatar in a chip, matching the wordmark chip', () => {
        setMockPage({ auth: { user: makeUser({ name: 'Ada Lovelace' }) } });
        render(<MobileTopBar />);
        expect(screen.getByLabelText('Inbox').closest('span')).toHaveClass(
            'bg-muted',
        );
        expect(
            screen.getByLabelText("Ada Lovelace's profile").closest('span'),
        ).toHaveClass('bg-muted');
    });

    it('wraps the back button in the same chip treatment as the wordmark', () => {
        setMockPage({}, '/activities/123', 'Runs/Show');
        render(<MobileTopBar />);
        expect(screen.getByLabelText('Back to History')).toHaveClass(
            'bg-muted',
        );
    });
});
