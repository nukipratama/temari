import { Fragment, type ReactNode } from 'react';

// split() with this capturing group keeps the matches: odd indices are the
// text that was wrapped in **…**. Safe to share across calls — split() does
// not rely on the regex's lastIndex.
const BOLD = /\*\*(.+?)\*\*/g;

/**
 * Renders Temari narration text, converting `**bold**` spans to <strong>.
 * Only bold is supported on purpose: the TemariPersona prompt allows a single
 * bold emphasis but bans all other markdown, so a full parser is unwarranted.
 *
 * Use this wherever LLM-generated narration is rendered, including inside a
 * caller's `renderContent` wrapper, so the emphasis lands instead of showing
 * literal `**` asterisks.
 */
/**
 * Narration is rendered inside decorative “…” quotes. When the model opens a
 * line by quoting a name (e.g. `"Zone Two Zen" jarang ketemu…`), that inner
 * quote collides with the decorative frame and reads as a doubled opening
 * quote. Drop a leading quote and its matching close so the decorative frame
 * is the only quote at the edge; mid-string quotes (a pace like 5'30") are
 * left untouched.
 */
export function stripEdgeQuotes(text: string): string {
    const trimmed = text.trimStart();
    const open = trimmed.charAt(0);
    if (open !== '"' && open !== '“' && open !== "'") {
        return text;
    }
    const close = open === '“' ? '”' : open;
    const closeIdx = trimmed.indexOf(close, 1);
    return closeIdx === -1 ? trimmed.slice(1) : trimmed.slice(1, closeIdx) + trimmed.slice(closeIdx + 1);
}

export function renderBold(text: string): ReactNode {
    // Split into positional fragments (odd indices are the bolded captures). The id is
    // baked once here so the render map keys off a data field, not the array index.
    return text
        .split(BOLD)
        .map((segment, i) => ({ segment, bold: i % 2 === 1, id: i }))
        .map(({ segment, bold, id }) =>
            bold ? (
                <strong key={id} className="font-bold">
                    {segment}
                </strong>
            ) : (
                <Fragment key={id}>{segment}</Fragment>
            ),
        );
}
