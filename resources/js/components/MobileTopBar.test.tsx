import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import MobileTopBar from './MobileTopBar';
import { makeUser, setMockPage } from '@/test/setup';

describe('MobileTopBar', () => {
    it('renders the brand mark link to home', () => {
        render(<MobileTopBar />);
        expect(screen.getByLabelText('Beranda')).toHaveAttribute('href', '/');
    });

    it('shows the user menu when a user is signed in', () => {
        setMockPage({ auth: { user: makeUser({ name: 'Ada Lovelace' }) } });
        render(<MobileTopBar />);
        expect(screen.getByLabelText('Buka menu Ada Lovelace')).toBeInTheDocument();
    });

    it('omits the user menu when there is no signed-in user', () => {
        setMockPage({ auth: { user: null } });
        render(<MobileTopBar />);
        expect(screen.queryByLabelText(/Buka menu/)).not.toBeInTheDocument();
    });

    it('renders the Strava sync badge in its disconnected state by default', () => {
        setMockPage({ auth: { user: null }, stravaSync: null });
        render(<MobileTopBar />);
        expect(screen.getByLabelText('Strava belum nyambung')).toBeInTheDocument();
    });
});
