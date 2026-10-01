import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import MetricExplainer from './MetricExplainer';

describe('MetricExplainer', () => {
    it('renders a trigger button labelled by the metric', () => {
        render(<MetricExplainer metricKey="ctl" />);
        expect(
            screen.getByRole('button', { name: 'Explain long-term load' }),
        ).toBeInTheDocument();
    });

    it('opens the popover on click and shows the glossary body', () => {
        render(<MetricExplainer metricKey="ctl" />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Explain long-term load' }),
        );
        expect(
            screen.getByRole('dialog', { name: 'long-term load' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /Your running load averaged over about six weeks/i,
            ),
        ).toBeInTheDocument();
    });

    it('shows acronym alongside label when one exists', () => {
        render(<MetricExplainer metricKey="ctl" />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Explain long-term load' }),
        );
        expect(screen.getByText('long-term load · CTL')).toBeInTheDocument();
    });

    it('omits the acronym separator for metrics without one', () => {
        render(<MetricExplainer metricKey="form" />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Explain load balance' }),
        );
        // Heading is just "load balance" — no " · " separator
        expect(screen.queryByText(/load balance ·/)).not.toBeInTheDocument();
    });

    it('closes the popover on second trigger click', async () => {
        render(<MetricExplainer metricKey="ctl" />);
        const trigger = screen.getByRole('button', {
            name: 'Explain long-term load',
        });
        fireEvent.click(trigger);
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        fireEvent.click(trigger);
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
    });

    it('closes on Escape', async () => {
        render(<MetricExplainer metricKey="ctl" />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Explain long-term load' }),
        );
        fireEvent.keyDown(document, { key: 'Escape' });
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
    });

    it('returns focus to the trigger button when Escape is pressed', async () => {
        render(<MetricExplainer metricKey="ctl" />);
        const trigger = screen.getByRole('button', {
            name: 'Explain long-term load',
        });
        trigger.focus();
        fireEvent.click(trigger);
        fireEvent.keyDown(document, { key: 'Escape' });
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(document.activeElement).toBe(trigger);
    });

    it('closes on pointerdown outside the trigger + popover', async () => {
        render(
            <div>
                <MetricExplainer metricKey="ctl" />
                <div data-testid="outside">outside</div>
            </div>,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Explain long-term load' }),
        );
        fireEvent.pointerDown(screen.getByTestId('outside'));
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
    });

    // WCAG 2.5.8: the visual box stays 24px at both sizes, while its pseudo
    // element expands the hit target without changing the label row.
    it.each([
        ['xs', '-m-1'],
        ['sm', '-m-0.5'],
    ] as const)(
        'keeps the %s trigger compact with a larger press target',
        (size, pullIn) => {
            render(<MetricExplainer metricKey="ctl" size={size} />);
            const trigger = screen.getByRole('button', {
                name: 'Explain long-term load',
            });
            expect(trigger).toHaveClass(
                'h-6',
                'w-6',
                pullIn,
                'relative',
                'before:absolute',
                'before:-inset-2.5',
                "before:content-['']",
                'pressable',
            );
            expect(trigger).toHaveAttribute('aria-haspopup', 'dialog');
        },
    );

    describe('viewport-edge alignment', () => {
        const originalGetBoundingClientRect =
            Element.prototype.getBoundingClientRect;
        const originalInnerWidth = window.innerWidth;

        afterEach(() => {
            Element.prototype.getBoundingClientRect =
                originalGetBoundingClientRect;
            window.innerWidth = originalInnerWidth;
        });

        // The alignment correction measures the popover's OWN rendered rect
        // (not the trigger's) — see MetricExplainer.tsx's correctedAlign.
        function stubPopoverRect(left: number, width = 256): void {
            Element.prototype.getBoundingClientRect = vi.fn().mockReturnValue({
                left,
                right: left + width,
                width,
                top: 0,
                bottom: 0,
                height: 0,
                x: left,
                y: 0,
                toJSON: () => ({}),
            });
        }

        it('stays centered when its own rendered rect already fits the viewport', () => {
            window.innerWidth = 1024;
            stubPopoverRect(400);
            render(<MetricExplainer metricKey="ctl" />);

            fireEvent.click(
                screen.getByRole('button', { name: 'Explain long-term load' }),
            );

            expect(screen.getByRole('dialog')).toHaveAttribute(
                'data-align',
                'center',
            );
        });

        it('flips to the left edge when the centered rect overflows the left of the viewport', () => {
            window.innerWidth = 1024;
            stubPopoverRect(-60);
            render(<MetricExplainer metricKey="ctl" />);

            fireEvent.click(
                screen.getByRole('button', { name: 'Explain long-term load' }),
            );

            expect(screen.getByRole('dialog')).toHaveAttribute(
                'data-align',
                'left',
            );
        });

        it('flips to the right edge when the centered rect overflows the right of the viewport', () => {
            window.innerWidth = 400;
            stubPopoverRect(300);
            render(<MetricExplainer metricKey="ctl" />);

            fireEvent.click(
                screen.getByRole('button', { name: 'Explain long-term load' }),
            );

            expect(screen.getByRole('dialog')).toHaveAttribute(
                'data-align',
                'right',
            );
        });

        it('resets to center on a fresh open after a previous correction', () => {
            window.innerWidth = 1024;
            stubPopoverRect(-60);
            render(<MetricExplainer metricKey="ctl" />);
            const trigger = screen.getByRole('button', {
                name: 'Explain long-term load',
            });

            fireEvent.click(trigger);
            expect(screen.getByRole('dialog')).toHaveAttribute(
                'data-align',
                'left',
            );

            fireEvent.click(trigger);
            stubPopoverRect(400);
            fireEvent.click(trigger);

            expect(screen.getByRole('dialog')).toHaveAttribute(
                'data-align',
                'center',
            );
        });
    });
});
