import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import Citation, { renderNarration } from './Citation';

const DRAWN = new Set(['session:today']);

describe('Citation', () => {
    it('names what it points at in its accessible label', () => {
        render(<Citation anchor="session:today">an easy run</Citation>);

        expect(
            screen.getByRole('button', {
                name: "Show today's session on this page",
            }),
        ).toHaveTextContent('an easy run');
    });

    it('falls back to the raw anchor when the grammar cannot label it', () => {
        render(<Citation anchor="session:tomorrow">whatever</Citation>);

        expect(
            screen.getByRole('button', {
                name: 'Show session:tomorrow on this page',
            }),
        ).toBeInTheDocument();
    });

    it('scrolls to the element that draws it and marks it', () => {
        const target = document.createElement('div');
        target.id = 'anchor-session-today';
        const scrollIntoView = vi.fn();
        target.scrollIntoView = scrollIntoView;
        document.body.append(target);

        render(<Citation anchor="session:today">an easy run</Citation>);
        fireEvent.click(screen.getByRole('button'));

        expect(scrollIntoView).toHaveBeenCalledWith({
            block: 'center',
            behavior: 'smooth',
        });
        expect(target.dataset.anchorHit).toBe('true');
        target.remove();
    });
});

describe('renderNarration', () => {
    it('renders a citation the page draws as a control', () => {
        render(
            <p>
                {renderNarration(
                    'keeping this to [an easy run](session:today) today',
                    DRAWN,
                )}
            </p>,
        );

        expect(
            screen.getByRole('button', {
                name: "Show today's session on this page",
            }),
        ).toHaveTextContent('an easy run');
    });

    it('degrades an anchor the page does not draw to plain prose', () => {
        render(
            <p>
                {renderNarration(
                    'keeping this to [an easy run](session:today) today',
                    new Set<string>(),
                )}
            </p>,
        );

        expect(screen.queryByRole('button')).toBeNull();
        expect(
            screen.getByText(/keeping this to an easy run today/),
        ).toBeInTheDocument();
    });

    it('keeps bold working either side of a citation', () => {
        const { container } = render(
            <p>
                {renderNarration(
                    'your form is **-8.4**, so [an easy run](session:today) it is',
                    DRAWN,
                )}
            </p>,
        );

        expect(container.querySelector('strong')).toHaveTextContent('-8.4');
        expect(screen.getByRole('button')).toHaveTextContent('an easy run');
    });

    /**
     * The server keeps at most one citation, but this renderer is also fed
     * hand-written specimens on the design page, so the split arithmetic has to
     * hold for more than one token.
     */
    it('handles more than one citation without dropping text', () => {
        render(
            <p>
                {renderNarration(
                    'before [one](session:today) middle [two](session:today) after',
                    DRAWN,
                )}
            </p>,
        );

        expect(screen.getAllByRole('button')).toHaveLength(2);
        expect(screen.getByText(/before/, { selector: 'p' })).toHaveTextContent(
            'before one middle two after',
        );
    });

    it('renders narration that cites nothing unchanged', () => {
        render(<p>{renderNarration('Easy run, 25-30 minutes.', DRAWN)}</p>);

        expect(screen.queryByRole('button')).toBeNull();
        expect(
            screen.getByText('Easy run, 25-30 minutes.'),
        ).toBeInTheDocument();
    });
});
