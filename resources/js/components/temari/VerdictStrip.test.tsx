import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import VerdictStrip from './VerdictStrip';
import type { VerdictTimelineItem } from '@/types/inertia';

function item(overrides: Partial<VerdictTimelineItem> = {}): VerdictTimelineItem {
    return {
        activityId: 1,
        mood: 'bouncy',
        moodFace: '🦘',
        oneline: 'verdict line',
        startedAt: '2026-05-10T08:00:00',
        distanceKm: 5.5,
        degraded: false,
        ...overrides,
    };
}

describe('VerdictStrip', () => {
    it('renders nothing when items is empty', () => {
        const { container } = render(<VerdictStrip items={[]} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders all verdicts with their oneline copy', () => {
        render(
            <VerdictStrip
                items={[item({ activityId: 1, oneline: 'first' }), item({ activityId: 2, oneline: 'second' })]}
            />,
        );
        expect(screen.getByText('first')).toBeInTheDocument();
        expect(screen.getByText('second')).toBeInTheDocument();
    });

    it('shows degraded chip on items that fell back', () => {
        render(<VerdictStrip items={[item({ degraded: true })]} />);
        expect(screen.getByText(/mode darurat/i)).toBeInTheDocument();
    });

    it('links each card to /runs/{id}', () => {
        render(<VerdictStrip items={[item({ activityId: 42 })]} />);
        const link = screen.getByRole('link');
        expect(link.getAttribute('href')).toBe('/runs/42');
    });

    it('formats distance with 1 decimal place', () => {
        render(<VerdictStrip items={[item({ distanceKm: 7.234 })]} />);
        expect(screen.getByText('7.2 km')).toBeInTheDocument();
    });

    it('arrow buttons fade in when there is overflow + clicking right scrolls', async () => {
        // jsdom doesn't lay out elements, so stub the geometry on every div
        // before render. This makes the strip think there's content overflowing
        // to the right (canRight = true) but nothing scrolled yet (canLeft = false).
        vi.spyOn(HTMLElement.prototype, 'scrollWidth', 'get').mockReturnValue(1000);
        vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(400);
        const scrollBy = vi.fn();
        // jsdom does not define scrollBy on HTMLElement; attach as a writable
        // property so each test sees a fresh spy.
        Object.defineProperty(HTMLElement.prototype, 'scrollBy', { value: scrollBy, configurable: true, writable: true });

        render(<VerdictStrip items={[item({ activityId: 1 }), item({ activityId: 2 }), item({ activityId: 3 })]} />);

        const right = screen.getByRole('button', { name: /Scroll kanan/i });
        const left = screen.getByRole('button', { name: /Scroll kiri/i });
        await waitFor(() => expect(right).toHaveClass(/opacity-100/));
        expect(left).toHaveClass(/opacity-0/);

        await userEvent.setup().click(right);
        expect(scrollBy).toHaveBeenCalledWith(expect.objectContaining({ left: 280, behavior: 'smooth' }));

        vi.restoreAllMocks();
    });

    it('left arrow becomes visible after scrolling and clicking it scrolls back', async () => {
        vi.spyOn(HTMLElement.prototype, 'scrollWidth', 'get').mockReturnValue(1000);
        vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(400);
        // Start scrolled: 200px to the right.
        let scrollLeft = 200;
        vi.spyOn(HTMLElement.prototype, 'scrollLeft', 'get').mockImplementation(() => scrollLeft);
        const scrollBy = vi.fn();
        // jsdom does not define scrollBy on HTMLElement; attach as a writable
        // property so each test sees a fresh spy.
        Object.defineProperty(HTMLElement.prototype, 'scrollBy', { value: scrollBy, configurable: true, writable: true });

        render(<VerdictStrip items={[item({ activityId: 1 }), item({ activityId: 2 })]} />);

        // Trigger scroll listener so the component re-reads geometry.
        const scroller = screen.getAllByRole('link')[0].parentElement?.parentElement;
        if (scroller !== null && scroller !== undefined) {
            fireEvent.scroll(scroller);
        }

        const left = await screen.findByRole('button', { name: /Scroll kiri/i });
        await waitFor(() => expect(left).toHaveClass(/opacity-100/));

        await userEvent.setup().click(left);
        expect(scrollBy).toHaveBeenCalledWith(expect.objectContaining({ left: -280, behavior: 'smooth' }));

        // Also exercise the resize handler.
        fireEvent(globalThis, new Event('resize'));

        vi.restoreAllMocks();
    });

});
