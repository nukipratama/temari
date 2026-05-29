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
export function renderBold(text: string): ReactNode {
    return text.split(BOLD).map((segment, i) =>
        i % 2 === 1 ? (
            <strong key={i} className="font-bold">
                {segment}
            </strong>
        ) : (
            <Fragment key={i}>{segment}</Fragment>
        ),
    );
}
