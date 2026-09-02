import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

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
        discriminator: '30d',
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
    it("labels the block Temari's read", () => {
        render(<NarrationCard analysis={payload()} />);
        expect(screen.getByText("Temari's read")).toBeInTheDocument();
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
});
