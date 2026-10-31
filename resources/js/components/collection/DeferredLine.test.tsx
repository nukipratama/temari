import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import DeferredLine from './DeferredLine';

vi.mock('@/components/collection/LazyLineChart', () => ({
    LazyLine: () => <div data-testid="line" />,
}));

beforeEach(() => {
    vi.useFakeTimers();
    vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) =>
        setTimeout(() => cb(0), 16),
    );
    vi.stubGlobal('cancelAnimationFrame', (id: number) => clearTimeout(id));
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('DeferredLine', () => {
    it('holds the same-size skeleton for a paint, then mounts the chart', () => {
        const { container } = render(
            <DeferredLine data={{ labels: [], datasets: [] }} />,
        );

        expect(screen.queryByTestId('line')).not.toBeInTheDocument();
        expect(container.querySelector('.skeleton')).toBeInTheDocument();

        act(() => {
            vi.runAllTimers();
        });

        expect(screen.getByTestId('line')).toBeInTheDocument();
        expect(container.querySelector('.skeleton')).not.toBeInTheDocument();
    });
});
