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

    it('renders rows grouped under a week header', () => {
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
        // Week range header reads "Senin, … — Minggu, …".
        expect(screen.getAllByText(/Senin/).length).toBeGreaterThan(0);
        expect(screen.getByText(/1 run/)).toBeInTheDocument();
        expect(screen.getByText(/5\.0 km/)).toBeInTheDocument();
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
        expect(screen.queryByRole('link', { name: /run/i })).not.toBeInTheDocument();
    });

    it('renders pagination links when last_page > 1', () => {
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
                        { url: '/aktivitas?page=1', label: '1', active: true },
                        { url: '/aktivitas?page=2', label: '2', active: false },
                        { url: '/aktivitas?page=2', label: 'Next &raquo;', active: false },
                    ],
                }}
            />,
        );
        expect(screen.getAllByRole('link').length).toBeGreaterThan(3);
    });

    it('renders the note line when a matching note is passed', () => {
        render(
            <RunsIndex
                runs={{
                    data: [
                        {
                            id: 7,
                            user_id: 1,
                            analyzed_at: '2026-05-10',
                            detail: {
                                id: 11,
                                activity_id: 7,
                                name: 'With note',
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
                notes={{ 7: { oneline: 'Solid run, keren tahanin pace-nya.', mood: 'bouncy' } }}
            />,
        );
        expect(screen.getByText('Solid run, keren tahanin pace-nya.')).toBeInTheDocument();
    });

    it('buckets activities without start_date_local under "Tanpa tanggal"', () => {
        render(
            <RunsIndex
                runs={{
                    data: [
                        {
                            id: 1,
                            user_id: 1,
                            analyzed_at: '2026-05-18',
                            detail: {
                                id: 11,
                                activity_id: 1,
                                name: 'Orphan run',
                                start_date_local: null,
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
        expect(screen.getByText('Tanpa tanggal')).toBeInTheDocument();
    });
});
