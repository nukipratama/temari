import { act, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import PaceTargetsCard from './PaceTargetsCard';

const PACES = { easy: 370, marathon: 320, threshold: 292, interval: 268 };

describe('PaceTargetsCard', () => {
    it('draws all four targets with their formatted pace', () => {
        render(<PaceTargetsCard paces={PACES} />);

        expect(screen.getByText('easy')).toBeInTheDocument();
        expect(screen.getByText('6:10')).toBeInTheDocument();
        expect(screen.getByText('marathon')).toBeInTheDocument();
        expect(screen.getByText('tempo')).toBeInTheDocument();
        expect(screen.getByText('interval')).toBeInTheDocument();
        expect(screen.getByText('4:28')).toBeInTheDocument();
    });

    it('anchors the slowest pace at the left of the rail and the fastest at the right', () => {
        const { container } = render(<PaceTargetsCard paces={PACES} />);
        const dots =
            container.querySelectorAll<HTMLElement>('i[style*="left"]');

        expect(dots[0].style.left).toBe('0%');
        expect(dots[3].style.left).toBe('100%');
    });

    it('holds the end labels inside the rail but centres the ones between', () => {
        const { container } = render(<PaceTargetsCard paces={PACES} />);
        const labels = [
            ...container.querySelectorAll<HTMLElement>('span[style*="left"]'),
        ];
        const dots = [
            ...container.querySelectorAll<HTMLElement>('i[style*="left"]'),
        ];
        const leftOf = (element: HTMLElement): number =>
            Number.parseFloat(element.style.left);

        expect(labels).toHaveLength(4);
        // Flush with the rail's left edge, and short of its right one.
        expect(leftOf(labels[0])).toBe(0);
        expect(leftOf(labels[3])).toBeLessThan(100);
        // The two between start left of their own dot by half a label.
        for (const index of [1, 2]) {
            expect(leftOf(labels[index])).toBeGreaterThan(
                leftOf(dots[index]) - 15,
            );
            expect(leftOf(labels[index])).toBeLessThan(leftOf(dots[index]));
        }
    });

    it('names the PR the targets came from, and when it was set', () => {
        render(
            <PaceTargetsCard
                paces={{
                    easy: 360,
                    marathon: 320,
                    threshold: 300,
                    interval: 280,
                }}
                source={{
                    category: 'half_marathon',
                    set_at: '2026-05-19',
                    stale: false,
                    quality_category: null,
                    quality_set_at: null,
                }}
            />,
        );

        expect(
            screen.getByText('from your half marathon pr, set may 2026'),
        ).toBeInTheDocument();
    });

    it('says so when no PR is recent enough, rather than passing an old one off as current', () => {
        render(
            <PaceTargetsCard
                paces={{
                    easy: 360,
                    marathon: 320,
                    threshold: 300,
                    interval: 280,
                }}
                source={{
                    category: '5km',
                    set_at: '2024-01-08',
                    stale: true,
                    quality_category: null,
                    quality_set_at: null,
                }}
            />,
        );

        expect(
            screen.getByText(
                'from your 5 km pr, set jan 2024 · nothing newer to go on',
            ),
        ).toBeInTheDocument();
    });

    it('names both records when tempo and interval read a fresher one', () => {
        render(
            <PaceTargetsCard
                paces={{
                    easy: 450,
                    marathon: 408,
                    threshold: 344,
                    interval: 323,
                }}
                source={{
                    category: 'half_marathon',
                    set_at: '2026-05-17',
                    stale: false,
                    quality_category: '5km',
                    quality_set_at: '2026-08-29',
                }}
            />,
        );

        expect(
            screen.getByText(
                'easy and marathon from your half marathon pr, set may 2026 · tempo and interval from your 5 km pr, set aug 2026',
            ),
        ).toBeInTheDocument();
    });

    it('centres every dot when all four paces are identical', () => {
        const { container } = render(
            <PaceTargetsCard
                paces={{
                    easy: 300,
                    marathon: 300,
                    threshold: 300,
                    interval: 300,
                }}
            />,
        );

        for (const dot of container.querySelectorAll<HTMLElement>(
            'i[style*="left"]',
        )) {
            expect(dot.style.left).toBe('50%');
        }
    });

    it('moves the label of a pace that crowds its neighbour, not its dot', () => {
        const { container } = render(
            <PaceTargetsCard
                paces={{
                    easy: 420,
                    marathon: 272,
                    threshold: 268,
                    interval: 265,
                }}
            />,
        );

        const leftOf = (element: HTMLElement): number =>
            Number.parseFloat(element.style.left);
        const labels = [
            ...container.querySelectorAll<HTMLElement>('span[style*="left"]'),
        ];
        const dots = [
            ...container.querySelectorAll<HTMLElement>('i[style*="left"]'),
        ];

        // Marathon and interval share the rail's right end, four percent apart.
        expect(leftOf(dots[3]) - leftOf(dots[1])).toBeLessThan(6);
        // Their labels do not, and both stay on the rail.
        expect(leftOf(labels[3]) - leftOf(labels[1])).toBeGreaterThan(20);
        expect(dots[3].style.left).toBe('100%');
        expect(leftOf(labels[3])).toBeLessThan(leftOf(dots[3]));
        expect(leftOf(labels[1])).toBeGreaterThan(0);
    });

    afterEach(() => {
        vi.restoreAllMocks();
        Reflect.deleteProperty(document, 'fonts');
    });

    it('remeasures the rail once webfonts finish loading', async () => {
        const clientWidthSpy = vi
            .spyOn(HTMLElement.prototype, 'clientWidth', 'get')
            .mockReturnValue(0);
        let resolveReady: () => void = () => {};
        const ready = new Promise<void>((resolve) => {
            resolveReady = resolve;
        });
        Object.defineProperty(document, 'fonts', {
            configurable: true,
            value: { ready },
        });

        render(<PaceTargetsCard paces={PACES} />);
        const callsBeforeReady = clientWidthSpy.mock.calls.length;

        resolveReady();
        await act(async () => {
            await ready;
        });

        expect(clientWidthSpy.mock.calls.length).toBeGreaterThan(
            callsBeforeReady,
        );
    });
});
