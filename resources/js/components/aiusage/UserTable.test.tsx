import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { UserRow } from '@/pages/AiUsage/types';

import UserTable from './UserTable';

function row(overrides: Partial<UserRow> = {}): UserRow {
    return {
        user_id: 1,
        user_name: 'Alice',
        strava_athlete_id: null,
        deleted: false,
        prompt: 500,
        completion: 230,
        total: 730,
        calls: 2,
        ...overrides,
    };
}

describe('UserTable', () => {
    it('renders a per-user table with named users under its own heading', () => {
        render(
            <UserTable
                rows={[
                    row(),
                    row({ user_id: 2, user_name: 'Bob', total: 75, calls: 1 }),
                ]}
                grandTotal={880}
            />,
        );

        expect(screen.getByText('Breakdown per User')).toBeInTheDocument();
        expect(screen.getByText('Alice')).toBeInTheDocument();
        expect(screen.getByText('Bob')).toBeInTheDocument();
    });

    it('keeps a min-width floor so the 6-col table scrolls (not clips) on mobile', () => {
        render(<UserTable rows={[row()]} grandTotal={880} />);

        expect(screen.getByRole('table').style.minWidth).toBe('520px');
    });

    it('draws each user share bar against the grand total', () => {
        render(<UserTable rows={[row()]} grandTotal={880} />);

        expect(
            screen.getByRole('progressbar', { name: '83.0% dari total' }),
        ).toBeInTheDocument();
    });

    it('draws an empty share bar rather than dividing by a zero grand total', () => {
        render(<UserTable rows={[row()]} grandTotal={0} />);

        expect(
            screen.getByRole('progressbar', { name: '0.0% dari total' }),
        ).toBeInTheDocument();
    });

    it('shows the average tokens per call', () => {
        render(<UserTable rows={[row()]} grandTotal={880} />);

        expect(screen.getByText('365')).toBeInTheDocument();
    });

    it('reports a zero average for a user with no calls', () => {
        render(
            <UserTable rows={[row({ calls: 0, total: 0 })]} grandTotal={880} />,
        );

        expect(screen.getAllByText('0').length).toBeGreaterThan(0);
    });

    it('falls back to "User #ID" when the name is gone', () => {
        render(
            <UserTable
                rows={[row({ user_id: 99, user_name: null })]}
                grandTotal={880}
            />,
        );

        expect(screen.getByText('User #99')).toBeInTheDocument();
    });

    it('marks a deleted account and still names it from the delete-time snapshot', () => {
        render(
            <UserTable
                rows={[
                    row({
                        user_id: 99,
                        user_name: 'Mantan Pelari',
                        strava_athlete_id: 12345,
                        deleted: true,
                    }),
                ]}
                grandTotal={880}
            />,
        );

        expect(screen.getByText('Mantan Pelari')).toBeInTheDocument();
        expect(screen.getByText('dihapus')).toBeInTheDocument();
        expect(screen.getByText('Strava 12345')).toBeInTheDocument();
    });

    it('shows the Strava id for a live account too, with no deleted marker', () => {
        render(
            <UserTable
                rows={[row({ strava_athlete_id: 777 })]}
                grandTotal={880}
            />,
        );

        expect(screen.getByText('Strava 777')).toBeInTheDocument();
        expect(screen.queryByText('dihapus')).not.toBeInTheDocument();
    });

    it('falls back to the empty state when nobody spent tokens in the window', () => {
        render(<UserTable rows={[]} grandTotal={0} />);

        expect(
            screen.getByText('Belum ada catatan token di rentang ini.'),
        ).toBeInTheDocument();
    });
});
