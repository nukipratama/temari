import { describe, expect, it, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import AiUsage from './AiUsage';

const routerGet = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Link: ({ href, children, className }: { href: string; children?: React.ReactNode; className?: string }) => (
        <a href={href} className={className}>{children}</a>
    ),
    router: {
        get: (...args: unknown[]) => routerGet(...args),
    },
}));

const baseProps = {
    from: '2026-05-01',
    to: '2026-05-19',
    kind: null as string | null,
    totals: { prompt: 600, completion: 280, total: 880, calls: 3, truncated_calls: 0 },
    byKind: [
        { kind: 'run-insight', prompt: 300, completion: 150, total: 450, calls: 1, truncated_calls: 0, avg_latency_ms: 800, max_latency_ms: 800 },
        { kind: 'briefing', prompt: 300, completion: 130, total: 430, calls: 2, truncated_calls: 0, avg_latency_ms: 1000, max_latency_ms: 1200 },
    ],
    byUser: [
        { user_id: 1, user_name: 'Alice', prompt: 500, completion: 230, total: 730, calls: 2 },
        { user_id: 2, user_name: 'Bob', prompt: 100, completion: 50, total: 150, calls: 1 },
    ],
    daily: [
        { day: '2026-05-18', prompt: 300, completion: 150, total: 450, calls: 1 },
        { day: '2026-05-19', prompt: 300, completion: 130, total: 430, calls: 2 },
    ],
    availableKinds: [
        { value: 'briefing', label: 'BriefingHeadline' },
        { value: 'run-insight', label: 'RunInsightTechnical' },
    ],
};

describe('AiUsage page', () => {
    beforeEach(() => {
        routerGet.mockClear();
    });

    it('shows totals + active date range + breakdown rows', () => {
        render(<AiUsage {...baseProps} />);

        // Heading
        expect(screen.getByText('AI Usage')).toBeInTheDocument();
        // Date range badge
        expect(screen.getByText('2026-05-01')).toBeInTheDocument();
        expect(screen.getByText('2026-05-19')).toBeInTheDocument();
        // Total tokens KPI
        expect(screen.getByText('880')).toBeInTheDocument();
        // Per-kind rows
        expect(screen.getByText('run-insight')).toBeInTheDocument();
        expect(screen.getByText('briefing')).toBeInTheDocument();
        // Prompt-share label
        expect(screen.getByText(/68% dari total/)).toBeInTheDocument();
        // The 8-col kind table keeps a min-w floor so it scrolls (not clips) on mobile.
        const kindTable = screen.getByText('run-insight').closest('table');
        expect(kindTable?.className).toContain('min-w-[680px]');
    });

    it('renders an empty state when byKind is empty', () => {
        render(
            <AiUsage
                {...baseProps}
                totals={{ prompt: 0, completion: 0, total: 0, calls: 0, truncated_calls: 0 }}
                byKind={[]}
                byUser={[]}
            />,
        );

        expect(screen.getAllByText('Belum ada catatan token di rentang ini.')).toHaveLength(2);
    });

    it('renders a per-user table with named users + share bar', () => {
        render(
            <AiUsage
                {...baseProps}
                byUser={[
                    { user_id: 1, user_name: 'Alice', prompt: 500, completion: 230, total: 730, calls: 2 },
                    { user_id: 2, user_name: 'Bob', prompt: 50, completion: 25, total: 75, calls: 1 },
                ]}
            />,
        );

        expect(screen.getByText('Alice')).toBeInTheDocument();
        expect(screen.getByText('Bob')).toBeInTheDocument();
        expect(screen.getByText('Breakdown per User')).toBeInTheDocument();
        // The 6-col user table keeps a min-w floor so it scrolls (not clips) on mobile.
        const userTable = screen.getByText('Alice').closest('table');
        expect(userTable?.className).toContain('min-w-[520px]');
    });

    it('falls back to "User #ID" for deleted users (user_name null, user_id present)', () => {
        render(
            <AiUsage
                {...baseProps}
                byUser={[{ user_id: 99, user_name: null, prompt: 10, completion: 5, total: 15, calls: 1 }]}
            />,
        );

        expect(screen.getByText('User #99')).toBeInTheDocument();
    });

    it('navigates with form submit', () => {
        render(<AiUsage {...baseProps} />);

        fireEvent.click(screen.getByRole('button', { name: /terapkan/i }));

        expect(routerGet).toHaveBeenCalledWith(
            '/ai-usage',
            { from: '2026-05-01', to: '2026-05-19' },
            { preserveState: true, preserveScroll: true },
        );
    });

    it('sends kind filter when one is selected', () => {
        render(<AiUsage {...baseProps} kind="briefing" />);

        fireEvent.click(screen.getByRole('button', { name: /terapkan/i }));

        expect(routerGet).toHaveBeenCalledWith(
            '/ai-usage',
            { from: '2026-05-01', to: '2026-05-19', kind: 'briefing' },
            { preserveState: true, preserveScroll: true },
        );
    });

    it.each([
        ['hari ini', /hari ini/i],
        ['7 hari', /7 hari/i],
        ['30 hari', /30 hari/i],
        ['bulan ini', /bulan ini/i],
    ])('preset "%s" is a link to a from/to date pair', (_label, pattern) => {
        render(<AiUsage {...baseProps} />);

        const link = screen.getByRole('link', { name: pattern });
        expect(link.getAttribute('href')).toMatch(
            /^\/ai-usage\?from=\d{4}-\d{2}-\d{2}&to=\d{4}-\d{2}-\d{2}$/,
        );
    });

    it('typing into a date field updates the form value', () => {
        const { container } = render(<AiUsage {...baseProps} />);
        const fromInput = container.querySelector('#from') as HTMLInputElement;

        fireEvent.change(fromInput, { target: { value: '2026-04-15' } });

        expect(fromInput.value).toBe('2026-04-15');
    });
});
