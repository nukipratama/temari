import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import RunsIndex from './Index';
import { setMockPage } from '@/test/setup';

beforeEach(() => {
    setMockPage({
        auth: { user: { id: 1, name: 'A', first_name: 'A', avatar_url: null } },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('Runs/Index', () => {
    it('renders empty state when no runs', () => {
        render(
            <RunsIndex
                runs={{
                    data: [],
                    current_page: 1,
                    last_page: 1,
                    per_page: 20,
                    total: 0,
                    links: [],
                }}
            />,
        );
        expect(screen.getByText(/Belum ada aktivitas/)).toBeInTheDocument();
    });

    it('renders rows when runs exist', () => {
        render(
            <RunsIndex
                runs={{
                    data: [
                        {
                            id: 1,
                            user_id: 1,
                            analyzed_at: '2026-05-10',
                            detail: {
                                id: 11,
                                activity_id: 1,
                                name: 'Morning Run',
                                start_date_local: '2026-05-10T07:00',
                                distance: 5000,
                                moving_time: 1800,
                                average_heartrate: 150,
                                trimp_edwards: 60,
                            },
                        },
                    ],
                    current_page: 1,
                    last_page: 1,
                    per_page: 20,
                    total: 1,
                    links: [],
                }}
            />,
        );
        expect(screen.getByText('Morning Run')).toBeInTheDocument();
    });

    it('skips runs missing detail', () => {
        render(
            <RunsIndex
                runs={{
                    // @ts-expect-error - detail intentionally missing
                    data: [{ id: 1, user_id: 1, analyzed_at: '2026-05-10' }],
                    current_page: 1,
                    last_page: 1,
                    per_page: 20,
                    total: 1,
                    links: [],
                }}
            />,
        );
        // Renders without crashing, no row produced.
        expect(screen.queryByRole('link', { name: /run/i })).not.toBeInTheDocument();
    });

    it('renders pagination links (active + inactive + disabled) when last_page > 1', () => {
        render(
            <RunsIndex
                runs={{
                    data: [
                        {
                            id: 1,
                            user_id: 1,
                            analyzed_at: '2026-05-10',
                            detail: {
                                id: 11,
                                activity_id: 1,
                                name: 'R',
                                start_date_local: '2026-05-10T07:00',
                                distance: 5000,
                                moving_time: 1800,
                                average_heartrate: 150,
                                trimp_edwards: 60,
                            },
                        },
                    ],
                    current_page: 1,
                    last_page: 2,
                    per_page: 20,
                    total: 40,
                    links: [
                        { url: null, label: '&laquo; Previous', active: false },
                        { url: '/runs?page=1', label: '1', active: true },
                        { url: '/runs?page=2', label: '2', active: false },
                        { url: '/runs?page=2', label: 'Next &raquo;', active: false },
                    ],
                }}
            />,
        );
        // Includes AppShell nav + brand + run row + active/inactive pagination links
        // (disabled "Previous" renders as a span, not a link).
        expect(screen.getAllByRole('link').length).toBeGreaterThan(3);
    });
});
