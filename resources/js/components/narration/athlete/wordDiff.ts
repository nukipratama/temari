export type DiffOp = 'same' | 'added' | 'removed';

export interface DiffToken {
    /** Stable render key: the tokens have no natural identity of their own. */
    id: number;
    op: DiffOp;
    text: string;
}

/**
 * Word-level diff of two narrations, as a longest-common-subsequence walk.
 * Narrations are a few hundred words at most, so the quadratic table is cheap
 * and the page needs no diff dependency.
 */
export function wordDiff(before: string, after: string): DiffToken[] {
    const a = splitWords(before);
    const b = splitWords(after);
    const lcs = lcsTable(a, b);

    const out: DiffToken[] = [];
    let i = 0;
    let j = 0;

    while (i < a.length && j < b.length) {
        if (a[i] === b[j]) {
            push(out, 'same', a[i]);
            i++;
            j++;
        } else if (lcs[i + 1][j] >= lcs[i][j + 1]) {
            push(out, 'removed', a[i]);
            i++;
        } else {
            push(out, 'added', b[j]);
            j++;
        }
    }

    while (i < a.length) {
        push(out, 'removed', a[i]);
        i++;
    }
    while (j < b.length) {
        push(out, 'added', b[j]);
        j++;
    }

    return out;
}

function splitWords(text: string): string[] {
    return text.split(/\s+/).filter((word) => word.length > 0);
}

/** Adjacent tokens of the same op join, so the render is spans not confetti. */
function push(out: DiffToken[], op: DiffOp, word: string): void {
    const last = out[out.length - 1];
    if (last !== undefined && last.op === op) {
        last.text = `${last.text} ${word}`;
        return;
    }
    out.push({ id: out.length, op, text: word });
}

function lcsTable(a: string[], b: string[]): number[][] {
    const table: number[][] = Array.from({ length: a.length + 1 }, () =>
        new Array<number>(b.length + 1).fill(0),
    );

    for (let i = a.length - 1; i >= 0; i--) {
        for (let j = b.length - 1; j >= 0; j--) {
            table[i][j] =
                a[i] === b[j]
                    ? table[i + 1][j + 1] + 1
                    : Math.max(table[i + 1][j], table[i][j + 1]);
        }
    }

    return table;
}
