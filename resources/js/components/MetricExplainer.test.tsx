import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import MetricExplainer from './MetricExplainer';

describe('MetricExplainer', () => {
    it('renders a trigger button labelled by the metric', () => {
        render(<MetricExplainer metricKey="ctl" />);
        expect(screen.getByRole('button', { name: 'Penjelasan Fitness' })).toBeInTheDocument();
    });

    it('opens the popover on click and shows the glossary body', () => {
        render(<MetricExplainer metricKey="ctl" />);
        fireEvent.click(screen.getByRole('button', { name: 'Penjelasan Fitness' }));
        expect(screen.getByRole('dialog', { name: 'Fitness' })).toBeInTheDocument();
        expect(screen.getByText(/Kebugaran rata-rata 42 hari terakhir/i)).toBeInTheDocument();
    });

    it('shows acronym alongside label when one exists', () => {
        render(<MetricExplainer metricKey="ctl" />);
        fireEvent.click(screen.getByRole('button', { name: 'Penjelasan Fitness' }));
        expect(screen.getByText('Fitness · CTL')).toBeInTheDocument();
    });

    it('omits the acronym separator for metrics without one', () => {
        render(<MetricExplainer metricKey="form" />);
        fireEvent.click(screen.getByRole('button', { name: 'Penjelasan Kesiapan' }));
        // Heading is just "Kesiapan" — no " · " separator
        expect(screen.queryByText(/Kesiapan ·/)).not.toBeInTheDocument();
    });

    it('closes the popover on second trigger click', async () => {
        render(<MetricExplainer metricKey="ctl" />);
        const trigger = screen.getByRole('button', { name: 'Penjelasan Fitness' });
        fireEvent.click(trigger);
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        fireEvent.click(trigger);
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    });

    it('closes on Escape', async () => {
        render(<MetricExplainer metricKey="ctl" />);
        fireEvent.click(screen.getByRole('button', { name: 'Penjelasan Fitness' }));
        fireEvent.keyDown(document, { key: 'Escape' });
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    });

    it('closes on pointerdown outside the trigger + popover', async () => {
        render(
            <div>
                <MetricExplainer metricKey="ctl" />
                <div data-testid="outside">outside</div>
            </div>,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Penjelasan Fitness' }));
        fireEvent.pointerDown(screen.getByTestId('outside'));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    });
});
