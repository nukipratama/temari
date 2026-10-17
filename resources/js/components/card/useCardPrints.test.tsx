import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { Print } from '@/lib/card/print';

import { ALL_FACTS, CARD_STYLES, type CardOptions } from '@/lib/card/types';
import { makeCardFacts } from '@/test/cardFacts';

import { useCardPrints } from './useCardPrints';

const renderPrint = vi.hoisted(() => vi.fn());
vi.mock('@/lib/card/print', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/lib/card/print')>()),
    renderPrint,
}));

let issued = 0;

function print(): Print {
    issued += 1;
    return {
        blob: new Blob(['png'], { type: 'image/png' }),
        url: `blob:print-${issued}`,
        width: 1080,
        height: 1920,
    };
}

const facts = makeCardFacts();

beforeEach(() => {
    issued = 0;
    renderPrint.mockReset();
    renderPrint.mockImplementation(async () => print());
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});
});

afterEach(() => vi.restoreAllMocks());

describe('useCardPrints', () => {
    it('draws the style on screen first, then its neighbours', async () => {
        renderHook(() => useCardPrints(facts, ALL_FACTS, 'story', 'topo'));

        await waitFor(() => expect(renderPrint).toHaveBeenCalledTimes(3));
        expect(renderPrint.mock.calls.map((call) => call[2])).toEqual([
            'topo',
            'broadsheet',
            'ticket',
        ]);
    });

    it('memoises a drawn print and never draws that tuple twice', async () => {
        const { result, rerender } = renderHook(
            ({ aspect }: { aspect: 'story' | 'feed' }) =>
                useCardPrints(facts, ALL_FACTS, aspect, 'broadsheet'),
            {
                initialProps: { aspect: 'story' } as {
                    aspect: 'story' | 'feed';
                },
            },
        );

        await waitFor(() => expect(renderPrint).toHaveBeenCalledTimes(3));

        rerender({ aspect: 'feed' });
        await waitFor(() => expect(renderPrint).toHaveBeenCalledTimes(6));

        rerender({ aspect: 'story' });
        await waitFor(() =>
            expect(result.current.states.broadsheet.print).not.toBeNull(),
        );
        expect(renderPrint).toHaveBeenCalledTimes(6);
    });

    it('keeps a print whose pass was superseded, so switching back is a lookup', async () => {
        let release: (() => void) | null = null;
        renderPrint.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    release = () => resolve(print());
                }),
        );
        const { result, rerender } = renderHook(
            ({ aspect }: { aspect: 'story' | 'feed' }) =>
                useCardPrints(facts, ALL_FACTS, aspect, 'broadsheet'),
            {
                initialProps: { aspect: 'story' } as {
                    aspect: 'story' | 'feed';
                },
            },
        );

        await waitFor(() => expect(renderPrint).toHaveBeenCalledTimes(1));
        rerender({ aspect: 'feed' });
        act(() => release?.());
        await waitFor(() => expect(renderPrint).toHaveBeenCalledTimes(4));

        const drawn = renderPrint.mock.calls.length;
        rerender({ aspect: 'story' });
        await waitFor(() =>
            expect(result.current.states.broadsheet.print).not.toBeNull(),
        );
        // Only the two story neighbours are left to draw; the print the
        // superseded pass finished is not drawn a second time.
        expect(renderPrint).toHaveBeenCalledTimes(drawn + 2);
    });

    it('shows a memoised print on the very render that switches back to it', async () => {
        const { result, rerender } = renderHook(
            ({ aspect }: { aspect: 'story' | 'feed' }) =>
                useCardPrints(facts, ALL_FACTS, aspect, 'broadsheet'),
            {
                initialProps: { aspect: 'story' } as {
                    aspect: 'story' | 'feed';
                },
            },
        );

        await waitFor(() => expect(renderPrint).toHaveBeenCalledTimes(3));
        const story = result.current.states.broadsheet.print;
        expect(story).not.toBeNull();
        rerender({ aspect: 'feed' });
        await waitFor(() => expect(renderPrint).toHaveBeenCalledTimes(6));

        rerender({ aspect: 'story' });

        expect(result.current.states.broadsheet.print).toBe(story);
    });

    it('draws nothing at all without facts', () => {
        renderHook(() => useCardPrints(null, ALL_FACTS, 'story', 'ticket'));

        expect(renderPrint).not.toHaveBeenCalled();
    });

    it('marks a style failed and redraws it on retry', async () => {
        renderPrint.mockRejectedValue(new Error('boom'));
        const { result } = renderHook(() =>
            useCardPrints(facts, ALL_FACTS, 'story', 'broadsheet'),
        );

        await waitFor(() =>
            expect(result.current.states.broadsheet.failed).toBe(true),
        );

        renderPrint.mockImplementation(async () => print());
        act(() => result.current.retry());

        await waitFor(() =>
            expect(result.current.states.broadsheet.print).not.toBeNull(),
        );
        expect(result.current.states.broadsheet.failed).toBe(false);
    });

    it('releases every object url it held when the popup goes', async () => {
        const { unmount } = renderHook(() =>
            useCardPrints(facts, ALL_FACTS, 'story', 'broadsheet'),
        );

        await waitFor(() => expect(renderPrint).toHaveBeenCalledTimes(3));
        unmount();

        expect(URL.revokeObjectURL).toHaveBeenCalledTimes(3);
    });

    it('revokes the oldest tuples once the memo outgrows four tuples of prints', async () => {
        const tuples: CardOptions[] = [
            { ...ALL_FACTS, hr: false },
            { ...ALL_FACTS, elevation: false },
            { ...ALL_FACTS, weather: false },
            { ...ALL_FACTS, badges: false },
            { ...ALL_FACTS, hr: false, elevation: false },
            { ...ALL_FACTS, hr: false, weather: false },
        ];
        const { result, rerender } = renderHook(
            ({ options }: { options: CardOptions }) =>
                useCardPrints(facts, options, 'story', 'broadsheet'),
            { initialProps: { options: tuples[0] } },
        );
        await waitFor(() => expect(renderPrint).toHaveBeenCalledTimes(3));

        for (const [index, options] of tuples.slice(1).entries()) {
            rerender({ options });
            await waitFor(() =>
                expect(renderPrint).toHaveBeenCalledTimes(
                    (index + 2) * CARD_STYLES.length,
                ),
            );
        }
        await waitFor(() =>
            expect(result.current.states.topo.print).not.toBeNull(),
        );

        const revoked = vi
            .mocked(URL.revokeObjectURL)
            .mock.calls.map(([url]) => url);
        expect(revoked).toEqual([
            'blob:print-1',
            'blob:print-2',
            'blob:print-3',
            'blob:print-4',
            'blob:print-5',
            'blob:print-6',
        ]);
        for (const each of CARD_STYLES) {
            expect(revoked).not.toContain(
                result.current.states[each].print?.url,
            );
        }
    });
});
