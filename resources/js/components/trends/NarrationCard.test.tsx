import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { AnalysisPayload } from '@/types/inertia';

import { stubNarrationLayout } from '@/test/setup';

import NarrationCard, { splitContent } from './NarrationCard';

function payload(overrides: Partial<AnalysisPayload> = {}): AnalysisPayload {
    return {
        id: null,
        status: 'pending',
        content: null,
        title: null,
        type: 'trend_read',
        is_zone_dependent: true,
        subject_type: 'trend_read_user_range',
        subject_id: 1,
        discriminator: 'arc',
        ...overrides,
    };
}

describe('splitContent', () => {
    it('splits a title/description pair on the first blank line', () => {
        expect(
            splitContent('Fitness is climbing.\n\nCTL moved from 40 to 55.'),
        ).toEqual({
            title: 'Fitness is climbing.',
            description: 'CTL moved from 40 to 55.',
        });
    });

    it('falls back to an empty description when there is no blank line', () => {
        expect(splitContent('Just a title.')).toEqual({
            title: 'Just a title.',
            description: '',
        });
    });
});

describe('NarrationCard', () => {
    it("labels the block temari's read, with no window that no longer holds", () => {
        render(<NarrationCard analysis={payload()} />);
        expect(screen.getByText(/^temari.?s read$/i)).toBeInTheDocument();
        expect(screen.queryByText(/last 7 days/i)).not.toBeInTheDocument();
    });

    it('draws a titled read as its headline over the story, paragraphs and bold kept, at prose width', () => {
        const { container } = render(
            <NarrationCard
                analysis={payload({
                    status: 'done',
                    title: 'eight weeks out, and the target is a stretch.',
                    content:
                        'The supported time came down.\n\nThe **long run** carried it.',
                })}
            />,
        );

        const headline = screen.getByText(
            'eight weeks out, and the target is a stretch.',
        );
        const story = screen.getByText(/The supported time came down\./);
        expect(headline).toHaveClass('font-serif');
        expect(headline.tagName).toBe('H3');
        expect(story).toHaveClass('narration');
        expect(story).not.toHaveClass('narration-dense');
        expect(story.textContent).toBe(
            'The supported time came down.The long run carried it.',
        );
        expect(story.querySelectorAll('[data-paragraph-gap]')).toHaveLength(1);
        expect(screen.getByText('long run').tagName).toBe('STRONG');
        expect(container.querySelector('section')).toHaveClass(
            '[&_p]:max-w-prose',
        );
    });

    it('clamps a long read behind a read more toggle', () => {
        stubNarrationLayout(200);
        render(
            <NarrationCard
                analysis={payload({
                    status: 'done',
                    title: 'eight weeks out, and the target is a stretch.',
                    content:
                        'The supported time came down.\n\nThe long run carried it.',
                })}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'read more' }),
        ).toHaveAttribute('aria-expanded', 'false');
    });

    it('splits an untitled read on its first blank line, at its old width', () => {
        render(
            <NarrationCard
                analysis={payload({
                    status: 'done',
                    content: 'Fitness is climbing.\n\nCTL moved from 40 to 55.',
                })}
            />,
        );

        expect(screen.getByText('Fitness is climbing.')).toHaveClass(
            'font-serif',
        );
        expect(
            screen.getByText('CTL moved from 40 to 55.'),
        ).toBeInTheDocument();
        expect(screen.getByRole('heading').closest('section')).not.toHaveClass(
            '[&_p]:max-w-prose',
        );
    });

    it('omits the description paragraph when there is none', () => {
        render(
            <NarrationCard
                analysis={payload({ status: 'done', content: 'Just a title.' })}
            />,
        );

        expect(screen.getByText('Just a title.')).toBeInTheDocument();
    });

    it.each(['pending', 'queued', 'processing', 'failed'] as const)(
        'renders the honest empty card with a try again button when %s',
        (status) => {
            render(<NarrationCard analysis={payload({ status })} />);

            expect(screen.getByText(/not written yet/)).toBeInTheDocument();
            expect(
                screen.getByRole('button', { name: /try again/ }),
            ).toBeInTheDocument();
        },
    );

    it('posts the trigger endpoint when "try again" is clicked', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => payload({ status: 'queued' }),
        });
        const original = globalThis.fetch;
        globalThis.fetch = fetchMock as unknown as typeof fetch;
        document.head.innerHTML = '<meta name="csrf-token" content="t" />';

        try {
            render(<NarrationCard analysis={payload({ status: 'failed' })} />);

            await act(async () => {
                fireEvent.click(
                    screen.getByRole('button', { name: /try again/ }),
                );
            });

            expect(fetchMock).toHaveBeenCalledTimes(1);
        } finally {
            globalThis.fetch = original;
        }
    });
});
