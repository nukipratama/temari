import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
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

    it('hides items beyond 6 and shows expand button when more exist', () => {
        const items = Array.from({ length: 8 }, (_, i) => item({ activityId: i + 1, oneline: `verdict ${i + 1}` }));
        render(<VerdictStrip items={items} />);
        expect(screen.getByText('verdict 1')).toBeInTheDocument();
        expect(screen.getByText('verdict 6')).toBeInTheDocument();
        expect(screen.queryByText('verdict 7')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Lihat 2 lainnya/i })).toBeInTheDocument();
    });

    it('reveals hidden items and toggles label on expand click', async () => {
        const items = Array.from({ length: 8 }, (_, i) => item({ activityId: i + 1, oneline: `run ${i + 1}` }));
        render(<VerdictStrip items={items} />);
        await userEvent.setup().click(screen.getByRole('button', { name: /Lihat 2 lainnya/i }));
        expect(screen.getByText('run 7')).toBeInTheDocument();
        expect(screen.getByText('run 8')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Sembunyikan/i })).toBeInTheDocument();
    });

});
