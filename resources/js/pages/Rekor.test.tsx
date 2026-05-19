import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Rekor from './Rekor';
import { setMockPage } from '@/test/setup';

beforeEach(() => {
    setMockPage({
        auth: { user: { id: 1, name: 'A', first_name: 'A', avatar_url: null } },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('Rekor', () => {
    it('renders empty state when no PRs', () => {
        render(<Rekor personalRecords={[]} />);
        expect(screen.getByText(/Belum ada PR/)).toBeInTheDocument();
    });

    it('renders PR card with formatted distance category + time', () => {
        render(
            <Rekor
                personalRecords={[
                    {
                        id: 1,
                        user_id: 1,
                        activity_id: 99,
                        category: '5km',
                        value: 1500,
                        value_sec: 1500,
                        set_at: '2026-05-01',
                        activity: { detail: { name: '5K Race' } },
                    },
                ]}
            />,
        );
        expect(screen.getByText('5 KM')).toBeInTheDocument();
        expect(screen.getByText('25:00')).toBeInTheDocument();
        expect(screen.getByText('5K Race')).toBeInTheDocument();
    });

    it('renders non-distance PR as pace/km', () => {
        render(
            <Rekor
                personalRecords={[
                    {
                        id: 1,
                        user_id: 1,
                        activity_id: 99,
                        category: 'best_pace',
                        value: 300,
                        value_sec: 300,
                        set_at: '2026-05-01',
                        activity: { detail: { name: 'Tempo' } },
                    },
                ]}
            />,
        );
        expect(screen.getByText('5:00/km')).toBeInTheDocument();
    });

    it('PR card without activity_id does not wrap in a link', () => {
        const { container } = render(
            <Rekor
                personalRecords={[
                    {
                        id: 1,
                        user_id: 1,
                        activity_id: null as unknown as number,
                        category: '5km',
                        value: 1500,
                        value_sec: 1500,
                        set_at: '2026-05-01',
                    },
                ]}
            />,
        );
        const links = container.querySelectorAll('a[href^="/aktivitas/"]');
        expect(links.length).toBe(0);
    });

    it('falls back to "Run" when PR activity has no detail name', () => {
        render(
            <Rekor
                personalRecords={[
                    {
                        id: 1,
                        user_id: 1,
                        activity_id: 99,
                        category: '5km',
                        value: 1500,
                        value_sec: 1500,
                        set_at: '2026-05-01',
                        activity: {},
                    },
                ]}
            />,
        );
        expect(screen.getByText('Run')).toBeInTheDocument();
    });

    it('renders context_analysis status panel when present', () => {
        render(
            <Rekor
                personalRecords={[
                    {
                        id: 1,
                        user_id: 1,
                        activity_id: 99,
                        category: '5km',
                        value: 1500,
                        value_sec: 1500,
                        set_at: '2026-05-01',
                        activity: { detail: { name: '5K' } },
                        context_analysis: {
                            id: 1,
                            status: 'done',
                            content: 'PR baru, mantap!',
                            type: 'pr_context',
                            subject_type: 'personal_record',
                            subject_id: 1,
                            discriminator: null,
                        },
                    },
                ]}
            />,
        );
        expect(screen.getByText('PR baru, mantap!')).toBeInTheDocument();
    });

    it.each([
        ['1km', '1 KM'],
        ['10km', '10 KM'],
        ['15km', '15 KM'],
        ['half_marathon', 'Half Marathon'],
        ['marathon', 'Marathon'],
    ])('renders %s PR with accent/pop variant label %s', (category, label) => {
        render(
            <Rekor
                personalRecords={[
                    {
                        id: 1,
                        user_id: 1,
                        activity_id: 99,
                        category,
                        value: 1500,
                        value_sec: 1500,
                        set_at: '2026-05-01',
                        activity: { detail: { name: 'Run' } },
                    },
                ]}
            />,
        );
        expect(screen.getByText(label)).toBeInTheDocument();
    });
});
