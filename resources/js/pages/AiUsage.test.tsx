import { describe, expect, it } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { router } from '@inertiajs/react';
import AiUsage from './AiUsage';
import { formMock, setMockPage } from '@/test/setup';

const baseProps = {
    range: 'custom' as 'today' | '7d' | '30d' | 'month' | 'all' | 'custom',
    from: '2026-05-01',
    to: '2026-05-19',
    kind: null as string | null,
    totals: { prompt: 600, completion: 280, total: 880, calls: 3, cost: 0.05, truncated_calls: 0 },
    previousTotals: { prompt: 500, completion: 200, total: 700, calls: 2, cost: 0.04 } as {
        prompt: number;
        completion: number;
        total: number;
        calls: number;
        cost: number;
    } | null,
    byKind: [
        { kind: 'run-insight', prompt: 300, completion: 150, total: 450, calls: 1, cost: 0.03, truncated_calls: 0, avg_latency_ms: 800, max_latency_ms: 800 },
        { kind: 'briefing', prompt: 300, completion: 130, total: 430, calls: 2, cost: 0.02, truncated_calls: 0, avg_latency_ms: 1000, max_latency_ms: 1200 },
    ],
    byUser: [
        { user_id: 1, user_name: 'Alice', prompt: 500, completion: 230, total: 730, calls: 2 },
        { user_id: 2, user_name: 'Bob', prompt: 100, completion: 50, total: 150, calls: 1 },
    ],
    byDeployment: [
        { deployment: 'nuki-mini', prompt: 600, completion: 280, total: 880, calls: 3, cost: 0.05, inputPer1m: 0.15, outputPer1m: 0.6 },
    ],
    daily: [
        { day: '2026-05-18', prompt: 300, completion: 150, total: 450, calls: 1, cost: 0.03 },
        { day: '2026-05-19', prompt: 300, completion: 130, total: 430, calls: 2, cost: 0.02 },
    ],
    availableKinds: [
        { value: 'briefing', label: 'BriefingHeadline' },
        { value: 'run-insight', label: 'RunInsightTechnical' },
    ],
    budget: { todayCost: 0.02, dailyCeiling: 0.1, currency: 'USD' },
    deadLettered: [],
    failedUnderBudget: [],
    nyangkut: [],
};

const deadLetteredGroup = {
    user_id: 7,
    user_name: 'Charlie',
    count: 2,
    blocks: [
        { type: 'weekly_recap', error: 'Azure down', failed_at: '2026-05-19T10:00:00+00:00' },
        { type: 'pr_context', error: null, failed_at: '2026-05-19T09:00:00+00:00' },
    ],
};

const nyangkutGroup = {
    user_id: 8,
    user_name: 'Dina',
    count: 1,
    blocks: [{ type: 'daily_greeting', error: null, failed_at: '2026-05-19T08:00:00+00:00' }],
};

describe('AiUsage page', () => {
    it('renders the flash info banner when present', () => {
        setMockPage({ flash: { info: 'Mencoba ulang 2 blok untuk Charlie.' } });
        render(<AiUsage {...baseProps} />);
        expect(screen.getByText('Mencoba ulang 2 blok untuk Charlie.')).toBeInTheDocument();
    });

    it('renders no flash banner when there is nothing to confirm', () => {
        render(<AiUsage {...baseProps} />);
        expect(screen.queryByLabelText('Tutup')).not.toBeInTheDocument();
    });

    it('hides the dead-letter panel when nothing is stuck', () => {
        render(<AiUsage {...baseProps} />);
        expect(screen.queryByText('Perlu perhatian')).not.toBeInTheDocument();
    });

    it('renders a per-user dead-letter group with its stuck-block count', () => {
        render(<AiUsage {...baseProps} deadLettered={[deadLetteredGroup]} />);
        expect(screen.getByText('Perlu perhatian')).toBeInTheDocument();
        expect(screen.getByText('Charlie')).toBeInTheDocument();
        expect(screen.getByText('2 blok berhenti dicoba otomatis')).toBeInTheDocument();
        expect(screen.getByText('weekly_recap')).toBeInTheDocument();
    });

    it('posts to the per-user retry route on "Coba lagi semua"', () => {
        render(<AiUsage {...baseProps} deadLettered={[deadLetteredGroup]} />);
        fireEvent.click(screen.getByRole('button', { name: /Coba lagi semua/ }));
        expect(formMock.post).toHaveBeenCalledWith('/ai-usage/users/7/retry-failed', expect.anything());
    });

    it('disables the retry button and shows "Mengirim…" while the retry is processing', () => {
        formMock.processing = true;
        render(<AiUsage {...baseProps} deadLettered={[deadLetteredGroup]} />);

        const button = screen.getByRole('button', { name: /Mengirim/ });
        expect(button).toBeDisabled();
        expect(screen.queryByRole('button', { name: /Coba lagi semua/ })).not.toBeInTheDocument();
    });

    it('renders the failed-under-budget bucket with a per-user re-arm button', () => {
        render(<AiUsage {...baseProps} failedUnderBudget={[deadLetteredGroup]} />);
        expect(screen.getByText('Failed, belum menyerah')).toBeInTheDocument();
        expect(screen.getByText('2 blok gagal, masih dicoba otomatis')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Coba lagi semua/ })).toBeInTheDocument();
    });

    it('renders the nyangkut bucket without a per-user button (global recover handles it)', () => {
        render(<AiUsage {...baseProps} nyangkut={[nyangkutGroup]} />);
        expect(screen.getByText('Nyangkut')).toBeInTheDocument();
        expect(screen.getByText('Dina')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Coba lagi semua/ })).not.toBeInTheDocument();
    });

    it('shows the global recover bar whenever any bucket is non-empty', () => {
        render(<AiUsage {...baseProps} nyangkut={[nyangkutGroup]} />);
        expect(screen.getByRole('button', { name: /Pulihkan semua/ })).toBeInTheDocument();
    });

    it('hides the recover bar when nothing is stuck', () => {
        render(<AiUsage {...baseProps} />);
        expect(screen.queryByRole('button', { name: /Pulihkan semua/ })).not.toBeInTheDocument();
    });

    it('posts to the recover route on "Pulihkan semua"', () => {
        render(<AiUsage {...baseProps} deadLettered={[deadLetteredGroup]} />);
        fireEvent.click(screen.getByRole('button', { name: /Pulihkan semua/ }));
        expect(formMock.post).toHaveBeenCalledWith('/ai-usage/recover', expect.anything());
    });

    it('shows totals + active date range + breakdown rows', () => {
        render(<AiUsage {...baseProps} />);

        // Heading
        expect(screen.getByText('AI Usage')).toBeInTheDocument();
        // Date range badge
        expect(screen.getByText('2026-05-01')).toBeInTheDocument();
        expect(screen.getByText('2026-05-19')).toBeInTheDocument();
        // Total tokens (880) appears in the KPI tile and the by-deployment row.
        expect(screen.getAllByText('880').length).toBeGreaterThan(0);
        // Per-deployment row
        expect(screen.getByText('nuki-mini')).toBeInTheDocument();
        // Per-kind rows
        expect(screen.getByText('run-insight')).toBeInTheDocument();
        expect(screen.getByText('briefing')).toBeInTheDocument();
        // Prompt-share label
        expect(screen.getByText(/68% dari total/)).toBeInTheDocument();
        // The 8-col kind table keeps a min-w floor so it scrolls (not clips) on mobile.
        const kindTable = screen.getByText('run-insight').closest('table');
        expect(kindTable?.style.minWidth).toBe('760px');
    });

    it('renders the per-deployment table with a cost column', () => {
        render(<AiUsage {...baseProps} />);

        expect(screen.getByText('Breakdown per Deployment')).toBeInTheDocument();
        expect(screen.getByText('nuki-mini')).toBeInTheDocument();
    });

    it('shows the per-deployment input/output rate in the deployment table', () => {
        render(
            <AiUsage
                {...baseProps}
                byDeployment={[
                    { deployment: 'nuki-5.2', prompt: 600, completion: 280, total: 880, calls: 3, cost: 0.05, inputPer1m: 1.75, outputPer1m: 14 },
                ]}
            />,
        );

        // The rate cell is the 2nd column: "$in / $out per 1M".
        const rateCell = screen.getByText('nuki-5.2').closest('tr')?.querySelector('td:nth-child(2)');
        expect(rateCell?.textContent).toMatch(/14/);
    });

    it('shows an em dash for a deployment with no configured rate', () => {
        render(
            <AiUsage
                {...baseProps}
                byDeployment={[
                    { deployment: 'mystery-deploy', prompt: 10, completion: 5, total: 15, calls: 1, cost: 0, inputPer1m: null, outputPer1m: null },
                ]}
            />,
        );

        const rateCell = screen.getByText('mystery-deploy').closest('tr')?.querySelector('td:nth-child(2)');
        expect(rateCell?.textContent).toBe('—');
    });

    it('renders the budget gauge with ceiling and a list-price caveat', () => {
        render(<AiUsage {...baseProps} />);

        expect(screen.getByText('Anggaran Hari Ini')).toBeInTheDocument();
        expect(screen.getByText(/list price/i)).toBeInTheDocument();
        // Gauge progressbar reflects todayCost / dailyCeiling = 20%.
        const gauge = screen.getByRole('progressbar', { name: /anggaran hari ini/i });
        expect(gauge.getAttribute('aria-valuenow')).toBe('20');
    });

    it('shows a no-ceiling state when dailyCeiling is null', () => {
        render(<AiUsage {...baseProps} budget={{ todayCost: 0.02, dailyCeiling: null, currency: 'USD' }} />);

        expect(screen.getByText(/tanpa batas/i)).toBeInTheDocument();
    });

    it('renders an empty state per table when data is empty', () => {
        render(
            <AiUsage
                {...baseProps}
                totals={{ prompt: 0, completion: 0, total: 0, calls: 0, cost: 0, truncated_calls: 0 }}
                byKind={[]}
                byUser={[]}
                byDeployment={[]}
            />,
        );

        // One empty state each for deployment, kind, and user tables.
        expect(screen.getAllByText('Belum ada catatan token di rentang ini.')).toHaveLength(3);
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
        expect(userTable?.style.minWidth).toBe('520px');
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

        expect(router.get).toHaveBeenCalledWith(
            '/ai-usage',
            { from: '2026-05-01', to: '2026-05-19' },
            { preserveState: true, preserveScroll: true },
        );
    });

    it('sends kind filter when one is selected', () => {
        render(<AiUsage {...baseProps} kind="briefing" />);

        fireEvent.click(screen.getByRole('button', { name: /terapkan/i }));

        expect(router.get).toHaveBeenCalledWith(
            '/ai-usage',
            { from: '2026-05-01', to: '2026-05-19', kind: 'briefing' },
            { preserveState: true, preserveScroll: true },
        );
    });

    it.each([
        ['hari ini', /hari ini/i, 'today'],
        ['7 hari', /7 hari/i, '7d'],
        ['30 hari', /30 hari/i, '30d'],
        ['bulan ini', /bulan ini/i, 'month'],
        ['semua', /semua/i, 'all'],
    ])('preset "%s" links to a date-free range token (durable, never stale)', (_label, pattern, token) => {
        render(<AiUsage {...baseProps} />);

        const link = screen.getByRole('link', { name: pattern });
        // Date-free: the href carries only the relative token, so it stays valid tomorrow.
        expect(link.getAttribute('href')).toBe(`/ai-usage?range=${token}`);
    });

    it('preset links preserve the active kind filter', () => {
        render(<AiUsage {...baseProps} kind="briefing" />);

        const link = screen.getByRole('link', { name: /7 hari/i });
        expect(link.getAttribute('href')).toBe('/ai-usage?range=7d&kind=briefing');
    });

    it('highlights the active preset', () => {
        render(<AiUsage {...baseProps} range="7d" />);

        const active = screen.getByRole('link', { name: /7 hari/i });
        const inactive = screen.getByRole('link', { name: /30 hari/i });
        expect(active.className).toContain('bg-sky');
        expect(inactive.className).toContain('bg-cream-deep');
    });

    it('applies the kind filter immediately on change, preserving the range', () => {
        render(<AiUsage {...baseProps} range="7d" />);

        fireEvent.change(screen.getByLabelText(/jenis/i), { target: { value: 'briefing' } });

        expect(router.get).toHaveBeenCalledWith(
            '/ai-usage',
            { range: '7d', kind: 'briefing' },
            { preserveState: true, preserveScroll: true },
        );
    });

    it('shows a vs-previous delta on the KPI tiles', () => {
        // total 880 vs 700 => +26%.
        render(<AiUsage {...baseProps} />);

        expect(screen.getAllByText(/vs sblm/).length).toBeGreaterThan(0);
        expect(screen.getByText(/26% vs sblm/)).toBeInTheDocument();
    });

    it('hides the delta when there is no comparable prior window', () => {
        render(<AiUsage {...baseProps} previousTotals={null} />);

        expect(screen.queryByText(/vs sblm/)).not.toBeInTheDocument();
    });

    it('typing into a date field updates the form value', () => {
        const { container } = render(<AiUsage {...baseProps} />);
        const fromInput = container.querySelector('#from') as HTMLInputElement;

        fireEvent.change(fromInput, { target: { value: '2026-04-15' } });

        expect(fromInput.value).toBe('2026-04-15');
    });
});
