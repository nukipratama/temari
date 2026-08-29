import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import TopNav from './TopNav';

const user = (overrides: Record<string, unknown> = {}) => ({
    auth: { user: makeUser(overrides) },
    flash: {},
    demoLoginEnabled: false,
});

beforeEach(() => {
    setMockPage({
        ...user(),
        stravaSync: { state: 'disconnected', last_synced_at: null },
    });
});

describe('TopNav', () => {
    it('renders the 4 primary tabs', () => {
        render(<TopNav />);
        expect(screen.getByText('Today')).toBeInTheDocument();
        expect(screen.getByText('Plan')).toBeInTheDocument();
        expect(screen.getByText('Trends')).toBeInTheDocument();
        expect(screen.getByText('History')).toBeInTheDocument();
    });

    it('highlights Plan for the /plan page, its own tab now', () => {
        setMockPage(user(), '/plan');
        render(<TopNav />);
        expect(screen.getByText('Plan')).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.getByText('Today')).not.toHaveAttribute('aria-current');
    });

    it('highlights History for the /history page', () => {
        setMockPage(user(), '/history');
        render(<TopNav />);
        expect(screen.getByText('History')).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('highlights Plan for the /race page, grouped with Plan per the IA ruling', () => {
        setMockPage(user(), '/race');
        render(<TopNav />);
        expect(screen.getByText('Plan')).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.getByText('Today')).not.toHaveAttribute('aria-current');
        expect(screen.getByText('History')).not.toHaveAttribute('aria-current');
    });

    it('gives tab links and the brand link a keyboard focus ring', () => {
        render(<TopNav />);
        const tab = screen.getByText('History').closest('a');
        expect(tab?.className).toMatch(/focus-ring/);
        const brand = screen.getByLabelText('Home');
        expect(brand.className).toMatch(/focus-ring/);
    });

    it('renders the disconnected Strava pill when no sync info', () => {
        render(<TopNav />);
        expect(screen.getByText('Strava')).toBeInTheDocument();
    });

    it('renders the synced Strava pill with relative time when connected', () => {
        setMockPage({
            ...user(),
            stravaSync: {
                state: 'ready',
                last_synced_at: new Date(
                    Date.now() - 5 * 60 * 1000,
                ).toISOString(),
            },
        });
        render(<TopNav />);
        expect(screen.getByText(/Strava synced/)).toBeInTheDocument();
    });

    it('renders synced label without timestamp when last_synced_at is null', () => {
        setMockPage({
            ...user(),
            stravaSync: { state: 'ready', last_synced_at: null },
        });
        render(<TopNav />);
        expect(screen.getByText('Strava synced')).toBeInTheDocument();
    });

    it('renders the avatar link to Profile for the signed-in user', () => {
        render(<TopNav />);
        expect(screen.getByLabelText(/Ada Lovelace's profile/)).toHaveAttribute(
            'href',
            '/profile',
        );
    });

    it('hides the avatar link when no user is in shared props', () => {
        setMockPage({
            auth: { user: null },
            flash: {},
            demoLoginEnabled: false,
        });
        render(<TopNav />);
        expect(screen.queryByLabelText(/'s profile/)).not.toBeInTheDocument();
    });

    it('highlights no bottom-nav tab for the /accessories page', () => {
        setMockPage(user(), '/accessories');
        render(<TopNav />);
        for (const label of ['Today', 'Plan', 'Trends', 'History']) {
            expect(screen.getByText(label)).not.toHaveAttribute('aria-current');
        }
    });

    it('highlights no bottom-nav tab for the nested /settings settings pages', () => {
        setMockPage(user(), '/settings/zones');
        render(<TopNav />);
        for (const label of ['Today', 'Plan', 'Trends', 'History']) {
            expect(screen.getByText(label)).not.toHaveAttribute('aria-current');
        }
    });

    it('activeTabFromUrl returns null for paths that do not match any prefix', () => {
        setMockPage(user(), '/xyz');
        render(<TopNav />);
        expect(screen.getByText('Today')).toBeInTheDocument();
    });
});
