import Citation, { renderNarration } from '@/components/temari/Citation';
import { type CatalogueEntry } from '@/lib/catalogue';

const CITED =
    "you're on a 12-week streak, but the last few days have been heavy, so I'm keeping this to [an easy run, 30-40 minutes](session:today). hold it around your normal 7:02/km.";

const DRAWN = new Set(['session:today']);

export default {
    name: 'Citation',
    description:
        'Narration words that point at what proves them: a dotted underline and nothing else. renderNarration draws a citation only when the page draws its anchor, and plain prose otherwise.',
    usage: `<p className="narration">{renderNarration(text, drawnAnchors)}</p>`,
    states: [
        {
            name: 'the anchor is drawn',
            render: () => (
                <p className="narration max-w-[380px]">
                    {renderNarration(CITED, DRAWN)}
                </p>
            ),
        },
        {
            name: 'the anchor is not drawn',
            render: () => (
                <p className="narration max-w-[380px]">
                    {renderNarration(CITED, new Set())}
                </p>
            ),
        },
        {
            name: 'alone',
            render: () => (
                <Citation anchor="session:today">
                    an easy run, 30-40 minutes
                </Citation>
            ),
        },
    ],
} satisfies CatalogueEntry;
