import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { AnalysisPayload } from '@/types/inertia';

import { makeUser, setMockPage } from '@/test/setup';

import NarrationFlag from './NarrationFlag';

function payload(overrides: Partial<AnalysisPayload> = {}): AnalysisPayload {
    return {
        id: 7,
        status: 'done',
        content: 'Halo',
        type: 'briefing_mascot_voice',
        is_zone_dependent: false,
        subject_type: 'briefing_user_day',
        subject_id: 1,
        discriminator: null,
        ...overrides,
    };
}

function renderFlag(overrides: Partial<AnalysisPayload> = {}) {
    setMockPage({ auth: { user: makeUser({ is_demo: false }) } });

    return render(<NarrationFlag analysis={payload(overrides)} />);
}

describe('NarrationFlag', () => {
    it('flags the narration row behind a done block', () => {
        renderFlag();

        expect(
            screen.getByRole('button', { name: 'flag this read' }),
        ).toBeInTheDocument();
    });

    it('draws an inert icon on a read already flagged', () => {
        renderFlag({ flagged: true });

        expect(screen.getByLabelText('flagged')).toBeInTheDocument();
        expect(screen.queryByRole('button')).toBeNull();
    });

    it('draws nothing before the block is done', () => {
        const { container } = renderFlag({ status: 'queued', content: null });

        expect(container).toBeEmptyDOMElement();
    });

    it('draws nothing when there is no row to flag', () => {
        const { container } = renderFlag({ id: null });

        expect(container).toBeEmptyDOMElement();
    });
});
