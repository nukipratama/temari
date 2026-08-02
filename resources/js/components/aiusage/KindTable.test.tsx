import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { UsageRow } from '@/pages/AiUsage/types';

import KindTable from './KindTable';

function row(overrides: Partial<UsageRow> = {}): UsageRow {
    return {
        kind: 'run-insight',
        prompt: 300,
        completion: 150,
        total: 450,
        calls: 1,
        cost: 0.03,
        truncated_calls: 0,
        avg_latency_ms: 800,
        max_latency_ms: 800,
        avg_steps: 3.5,
        cached_pct: 71.2,
        reasoning_pct: 18.4,
        ...overrides,
    };
}

const unmeasured = row({
    kind: 'briefing',
    avg_steps: null,
    cached_pct: null,
    reasoning_pct: null,
    avg_latency_ms: 1000,
    max_latency_ms: 1200,
});

describe('KindTable', () => {
    it('renders one row per kind under its own heading', () => {
        render(
            <KindTable
                rows={[row(), unmeasured]}
                grandTotal={880}
                currency="USD"
            />,
        );

        expect(screen.getByText('Breakdown per Kind')).toBeInTheDocument();
        expect(screen.getByText('run-insight')).toBeInTheDocument();
        expect(screen.getByText('briefing')).toBeInTheDocument();
    });

    it('keeps a min-width floor so the 8-col table scrolls (not clips) on mobile', () => {
        render(<KindTable rows={[row()]} grandTotal={880} currency="USD" />);

        expect(screen.getByRole('table').style.minWidth).toBe('760px');
    });

    it('shows the agent summary for an instrumented kind and omits it for an unmeasured one', () => {
        render(
            <KindTable
                rows={[row(), unmeasured]}
                grandTotal={880}
                currency="USD"
            />,
        );

        expect(
            screen.getByText('3.5 langkah · 71% cache · 18% reasoning'),
        ).toBeInTheDocument();
        expect(screen.queryAllByText(/langkah/)).toHaveLength(1);
    });

    it('renders the agent line from whichever of the three measures arrived', () => {
        render(
            <KindTable
                rows={[row({ cached_pct: null, reasoning_pct: null })]}
                grandTotal={880}
                currency="USD"
            />,
        );

        expect(screen.getByText('3.5 langkah')).toBeInTheDocument();
    });

    it('treats a measured zero as measured, not as missing', () => {
        render(
            <KindTable
                rows={[row({ avg_steps: 0, cached_pct: 0, reasoning_pct: 0 })]}
                grandTotal={880}
                currency="USD"
            />,
        );

        expect(
            screen.getByText('0.0 langkah · 0% cache · 0% reasoning'),
        ).toBeInTheDocument();
    });

    it('shows latency as avg/max in seconds', () => {
        render(
            <KindTable
                rows={[row({ avg_latency_ms: 1400, max_latency_ms: 5200 })]}
                grandTotal={880}
                currency="USD"
            />,
        );

        expect(screen.getByText('1 / 5 dtk')).toBeInTheDocument();
    });

    it('falls back to the average when no max latency was recorded', () => {
        render(
            <KindTable
                rows={[row({ avg_latency_ms: 3000, max_latency_ms: null })]}
                grandTotal={880}
                currency="USD"
            />,
        );

        expect(screen.getByText('3 / 3 dtk')).toBeInTheDocument();
    });

    it('shows an em dash in the latency cell when latency was never measured', () => {
        render(
            <KindTable
                rows={[row({ avg_latency_ms: null, max_latency_ms: null })]}
                grandTotal={880}
                currency="USD"
            />,
        );

        const latencyCell = screen
            .getByText('run-insight')
            .closest('tr')
            ?.querySelector('td:nth-child(7)');
        expect(latencyCell?.textContent).toBe('—');
    });

    it('shows an em dash in the truncated cell when nothing was cut off', () => {
        render(<KindTable rows={[row()]} grandTotal={880} currency="USD" />);

        const truncatedCell = screen
            .getByText('run-insight')
            .closest('tr')
            ?.querySelector('td:nth-child(8)');
        expect(truncatedCell?.textContent).toBe('—');
    });

    it('reports the truncated count with its rate once any call was cut off', () => {
        render(
            <KindTable
                rows={[row({ calls: 4, truncated_calls: 1 })]}
                grandTotal={880}
                currency="USD"
            />,
        );

        expect(screen.getByText('1 (25.0%)')).toBeInTheDocument();
    });

    it('draws each kind share bar against the grand total', () => {
        render(<KindTable rows={[row()]} grandTotal={880} currency="USD" />);

        expect(
            screen.getByRole('progressbar', { name: '51.1% dari total' }),
        ).toBeInTheDocument();
    });

    it('avoids dividing by a zero grand total or a zero call count', () => {
        render(
            <KindTable
                rows={[row({ calls: 0, truncated_calls: 0 })]}
                grandTotal={0}
                currency="USD"
            />,
        );

        expect(
            screen.getByRole('progressbar', { name: '0.0% dari total' }),
        ).toBeInTheDocument();
    });

    it('falls back to the empty state when no kind billed in the window', () => {
        render(<KindTable rows={[]} grandTotal={0} currency="USD" />);

        expect(
            screen.getByText('Belum ada catatan token di rentang ini.'),
        ).toBeInTheDocument();
    });
});
