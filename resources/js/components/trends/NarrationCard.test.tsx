import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { AnalysisPayload } from '@/types/inertia';

import NarrationCard, { splitContent } from './NarrationCard';

function payload(overrides: Partial<AnalysisPayload> = {}): AnalysisPayload {
    return {
        id: null,
        status: 'pending',
        content: null,
        type: 'trend_read',
        is_zone_dependent: true,
        subject_type: 'trend_read_user_range',
        subject_id: 1,
        discriminator: '7d',
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
    it("labels the block temari's read, last 7 days", () => {
        render(<NarrationCard analysis={payload()} />);
        expect(
            screen.getByText(/temari.?s read . last 7 days/i),
        ).toBeInTheDocument();
    });

    it('renders the title as a headline and the description below it', () => {
        render(
            <NarrationCard
                analysis={payload({
                    status: 'done',
                    content: 'Fitness is climbing.\n\nCTL moved from 40 to 55.',
                })}
            />,
        );

        expect(screen.getByText('Fitness is climbing.')).toBeInTheDocument();
        expect(
            screen.getByText('CTL moved from 40 to 55.'),
        ).toBeInTheDocument();
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
