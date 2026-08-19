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
        expect(screen.getByText('Trends')).toBeInTheDocument();
        expect(screen.getByText('History')).toBeInTheDocument();
        expect(screen.getByText('Me')).toBeInTheDocument();
    });

    it('highlights Today for the /plan page, a drill-in', () => {
        setMockPage(user(), '/plan');
        render(<TopNav />);
        expect(screen.getByText('Today')).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('highlights History for the /history page', () => {
        setMockPage(user(), '/history');
        render(<TopNav />);
        expect(screen.getByText('History')).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('highlights Today for the /race page, a drill-in', () => {
        setMockPage(user(), '/race');
        render(<TopNav />);
        expect(screen.getByText('Today')).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.getByText('Me')).not.toHaveAttribute('aria-current');
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

    it('renders the avatar menu for the signed-in user', () => {
        render(<TopNav />);
        expect(
            screen.getByLabelText(/Open menu for Ada Lovelace/),
        ).toBeInTheDocument();
    });

    it('hides the avatar menu when no user is in shared props', () => {
        setMockPage({
            auth: { user: null },
            flash: {},
            demoLoginEnabled: false,
        });
        render(<TopNav />);
        expect(screen.queryByLabelText(/Open menu/)).not.toBeInTheDocument();
    });

    it('highlights Me for the /accessories page', () => {
        setMockPage(user(), '/accessories');
        render(<TopNav />);
        expect(screen.getByText('Me')).toHaveAttribute('aria-current', 'page');
    });

    it('highlights Me for the nested /settings settings pages', () => {
        setMockPage(user(), '/settings/zones');
        render(<TopNav />);
        expect(screen.getByText('Me')).toHaveAttribute('aria-current', 'page');
    });

    it('activeTabFromUrl returns null for paths that do not match any prefix', () => {
        setMockPage(user(), '/xyz');
        render(<TopNav />);
        expect(screen.getByText('Today')).toBeInTheDocument();
    });
});
