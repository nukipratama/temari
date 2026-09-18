import { useCallback, useEffect, useRef, useState } from 'react';

import type { Print } from '@/lib/card/print';
import type {
    CardAspect,
    CardFactsPayload,
    CardOptions,
    CardStyle,
} from '@/lib/card/types';

import { printKey, renderPrint } from '@/lib/card/print';
import { CARD_STYLES } from '@/lib/card/types';

export interface PrintState {
    /** The print for the current tuple, once it has been drawn. */
    print: Print | null;
    /** The last print this style drew, kept so a switch dims rather than blanks. */
    previous: Print | null;
    failed: boolean;
}

/** The last thing each style produced, and which tuple it was for. */
type Result = { key: string; print: Print | null };

type States = Record<CardStyle, PrintState>;

/**
 * Draws all three prints for the current aspect and fact toggles, current style
 * first so the hero lands before its neighbours, and memoises every result by
 * `{style, aspect, facts}` — switching back to a tuple already drawn is a map
 * lookup, not a redraw.
 */
export function useCardPrints(
    facts: CardFactsPayload | null,
    options: CardOptions,
    aspect: CardAspect,
    style: CardStyle,
): { states: States; retry: () => void } {
    const [results, setResults] = useState<Partial<Record<CardStyle, Result>>>(
        {},
    );
    const [attempt, setAttempt] = useState(0);
    const memoRef = useRef(new Map<string, Print>());

    useEffect(() => {
        const cache = memoRef.current;

        return () => {
            for (const print of cache.values()) URL.revokeObjectURL(print.url);
            cache.clear();
        };
    }, []);

    useEffect(() => {
        if (facts === null) return;

        let live = true;
        // The style on screen is drawn first; the two peeking past the stage
        // edge can arrive a beat later without anyone noticing.
        const order = [style, ...CARD_STYLES.filter((each) => each !== style)];

        void (async () => {
            for (const each of order) {
                const key = printKey(each, aspect, options);
                if (memoRef.current.has(key)) continue;

                let print: Print | null = null;
                try {
                    print = await renderPrint(facts, options, each, aspect);
                } catch {
                    print = null;
                }
                if (!live) {
                    if (print !== null) URL.revokeObjectURL(print.url);
                    return;
                }
                if (print !== null) memoRef.current.set(key, print);
                setResults((prev) => ({ ...prev, [each]: { key, print } }));
            }
        })();

        return () => {
            live = false;
        };
    }, [facts, options, aspect, style, attempt]);

    const retry = useCallback(() => setAttempt((n) => n + 1), []);

    const states = {} as States;
    for (const each of CARD_STYLES) {
        const key = printKey(each, aspect, options);
        const result = results[each];
        states[each] = {
            print: memoRef.current.get(key) ?? null,
            previous: result?.print ?? null,
            failed: result?.key === key && result.print === null,
        };
    }

    return { states, retry };
}
