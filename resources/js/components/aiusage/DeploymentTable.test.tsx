import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { DeploymentRow } from '@/pages/AiUsage/types';

import DeploymentTable from './DeploymentTable';

function row(overrides: Partial<DeploymentRow> = {}): DeploymentRow {
    return {
        deployment: 'nuki-mini',
        prompt: 600,
        completion: 280,
        total: 880,
        calls: 3,
        cost: 0.05,
        inputPer1m: 0.15,
        outputPer1m: 0.6,
        ...overrides,
    };
}

describe('DeploymentTable', () => {
    it('renders one row per deployment under its own heading', () => {
        render(<DeploymentTable rows={[row()]} currency="USD" />);

        expect(
            screen.getByText('Breakdown per Deployment'),
        ).toBeInTheDocument();
        expect(screen.getByText('nuki-mini')).toBeInTheDocument();
        expect(screen.getByText('$0,05')).toBeInTheDocument();
    });

    it('keeps a min-width floor so the 7-col table scrolls (not clips) on mobile', () => {
        render(<DeploymentTable rows={[row()]} currency="USD" />);

        expect(screen.getByRole('table').style.minWidth).toBe('640px');
    });

    it('shows the per-deployment input/output rate as a paired cell', () => {
        render(
            <DeploymentTable
                rows={[
                    row({
                        deployment: 'nuki-5.2',
                        inputPer1m: 1.75,
                        outputPer1m: 14,
                    }),
                ]}
                currency="USD"
            />,
        );

        const rateCell = screen
            .getByText('nuki-5.2')
            .closest('tr')
            ?.querySelector('td:nth-child(2)');
        expect(rateCell?.textContent).toBe('$1,75 / $14,00');
    });

    it('shows an em dash for a deployment with no configured rate', () => {
        render(
            <DeploymentTable
                rows={[
                    row({
                        deployment: 'mystery-deploy',
                        inputPer1m: null,
                        outputPer1m: null,
                    }),
                ]}
                currency="USD"
            />,
        );

        const rateCell = screen
            .getByText('mystery-deploy')
            .closest('tr')
            ?.querySelector('td:nth-child(2)');
        expect(rateCell?.textContent).toBe('—');
    });

    it('shows an em dash when only one side of the rate pair is configured', () => {
        render(
            <DeploymentTable
                rows={[
                    row({
                        deployment: 'half-priced',
                        inputPer1m: 1.75,
                        outputPer1m: null,
                    }),
                ]}
                currency="USD"
            />,
        );

        const rateCell = screen
            .getByText('half-priced')
            .closest('tr')
            ?.querySelector('td:nth-child(2)');
        expect(rateCell?.textContent).toBe('—');
    });

    it('falls back to the empty state when no deployment billed in the window', () => {
        render(<DeploymentTable rows={[]} currency="USD" />);

        expect(
            screen.getByText('Belum ada catatan token di rentang ini.'),
        ).toBeInTheDocument();
    });
});
