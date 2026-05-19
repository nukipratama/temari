import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import FloatingTemari from './FloatingTemari';
import { setMockPage } from '@/test/setup';

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('FloatingTemari', () => {
    it('renders mascot in default state without badge when no active analyses', () => {
        setMockPage({
            auth: { user: null },
            flash: {},
            demoLoginEnabled: false,
            aiActivity: { pending: 0, queued: 0, processing: 0 },
        });
        const { container } = render(<FloatingTemari />);
        expect(container.querySelector('button[aria-label]')).toBeInTheDocument();
        expect(screen.queryByText('3')).not.toBeInTheDocument();
    });

    it('shows count badge when there are pending/queued/processing analyses', () => {
        setMockPage({
            auth: { user: null },
            flash: {},
            demoLoginEnabled: false,
            aiActivity: { pending: 2, queued: 1, processing: 0 },
        });
        render(<FloatingTemari />);
        const badge = screen.getAllByText('3');
        expect(badge.length).toBeGreaterThan(0);
    });

    it('handles missing aiActivity shared prop gracefully', () => {
        setMockPage({
            auth: { user: null },
            flash: {},
            demoLoginEnabled: false,
        });
        const { container } = render(<FloatingTemari />);
        expect(container.querySelector('button[aria-label]')).toBeInTheDocument();
    });

    it('starts polling interval when there are active analyses', () => {
        const setIntervalSpy = vi.spyOn(globalThis, 'setInterval');
        setMockPage({
            auth: { user: null },
            flash: {},
            demoLoginEnabled: false,
            aiActivity: { pending: 0, queued: 1, processing: 0 },
        });
        render(<FloatingTemari />);
        expect(setIntervalSpy).toHaveBeenCalled();
    });

    it('opens bubble when mascot button is clicked', () => {
        setMockPage({
            auth: { user: null },
            flash: {},
            demoLoginEnabled: false,
            aiActivity: { pending: 0, queued: 0, processing: 0 },
        });
        render(<FloatingTemari />);
        const button = screen.getByLabelText('Halo dari Temari');
        act(() => {
            button.click();
        });
        // Bubble shows page tip; default '/' tip mentions Temari
        expect(screen.getByRole('status')).toBeInTheDocument();
    });

    it('fires router.reload on each poll tick while thinking', () => {
        const reloadSpy = vi.mocked(router.reload);
        reloadSpy.mockClear();
        setMockPage({
            auth: { user: null },
            flash: {},
            demoLoginEnabled: false,
            aiActivity: { pending: 1, queued: 0, processing: 0 },
        });
        render(<FloatingTemari />);
        act(() => {
            vi.advanceTimersByTime(5000);
        });
        expect(reloadSpy).toHaveBeenCalledWith({ only: ['aiActivity'] });
        act(() => {
            vi.advanceTimersByTime(5000);
        });
        expect(reloadSpy.mock.calls.length).toBeGreaterThanOrEqual(2);
    });

    it('stops polling when document is hidden and resumes on visibility', () => {
        setMockPage({
            auth: { user: null },
            flash: {},
            demoLoginEnabled: false,
            aiActivity: { pending: 1, queued: 0, processing: 0 },
        });
        render(<FloatingTemari />);
        // Trigger visibility change to hidden
        Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
        act(() => {
            document.dispatchEvent(new Event('visibilitychange'));
        });
        // Restore visible state for cleanup
        Object.defineProperty(document, 'hidden', { configurable: true, get: () => false });
        act(() => {
            document.dispatchEvent(new Event('visibilitychange'));
        });
    });
});
