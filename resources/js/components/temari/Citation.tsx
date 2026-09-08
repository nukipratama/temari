import { Fragment, type ReactNode } from 'react';

import { anchorLabel, revealAnchor } from '@/lib/anchors';
import { renderBold } from '@/lib/richText';

/**
 * A span of narration that points at the thing proving it: the cited words
 * carry a dotted underline and nothing else.
 *
 * Chosen over a trailing chip and a superscript marker by building all three
 * against real narration in /devtools/design. The chip restated what the words
 * already said, cost a line of height and orphaned the punctuation after it;
 * the marker was too quiet to read as a control and never showed which words
 * it covered.
 */
export default function Citation({
    anchor,
    children,
}: Readonly<{ anchor: string; children: string }>) {
    const label = anchorLabel(anchor);

    return (
        <button
            type="button"
            onClick={() => revealAnchor(anchor)}
            aria-label={`Show ${label ?? anchor} on this page`}
            className="focus-ring rounded-xs underline decoration-text-3 decoration-dotted underline-offset-4 transition hover:text-foreground hover:decoration-foreground"
        >
            {children}
        </button>
    );
}

// split() with two capturing groups keeps them: the cited words then the
// anchor, so each token costs three positions.
const CITATION = /\[([^\]\n]+)\]\(([^)\s]+)\)/g;

/**
 * Narration with `**bold**` and at most one `[words](anchor)` citation. The
 * server drops citations that do not resolve against the subject's data; this
 * drops the ones that resolve but have no element drawing them on this page,
 * rendering those as ordinary prose.
 */
export function renderNarration(
    text: string,
    drawn: ReadonlySet<string>,
): ReactNode {
    const parts = text.split(CITATION);
    const nodes: ReactNode[] = [];

    for (let i = 0; i < parts.length; i += 3) {
        nodes.push(<Fragment key={i}>{renderBold(parts[i])}</Fragment>);

        if (i + 2 < parts.length) {
            const words = parts[i + 1];
            const anchor = parts[i + 2];
            nodes.push(
                drawn.has(anchor) ? (
                    <Citation key={i + 1} anchor={anchor}>
                        {words}
                    </Citation>
                ) : (
                    <Fragment key={i + 1}>{renderBold(words)}</Fragment>
                ),
            );
        }
    }

    return nodes;
}
