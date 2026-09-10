import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { NarrationRow } from '@/pages/Narration/types';

import NarrationsTab, { narrationsHref } from './NarrationsTab';

const aRow: NarrationRow = {
    id: 12,
    kind: 'trend_read',
    discriminator: null,
    status: 'done',
    served_by: 'llm',
    origin: 'scheduled',
    cost: 0.02,
    last_cost: 0.02,
    prompt_tokens: 100,
    completion_tokens: 40,
    latency_ms: 500,
    steps: 1,
    tool_calls: [],
    content: 'a read.',
    error: null,
    generated_at: null,
    flag: null,
    version_count: 0,
    previous_content: null,
};

function renderTab(
    overrides: Partial<Parameters<typeof NarrationsTab>[0]> = {},
) {
    return render(
        <NarrationsTab
            narrations={[aRow]}
            nextCursor={null}
            filters={{ kind: null, status: null, before: null }}
            availableKinds={['trend_read', 'weekly_recap']}
            availableStatuses={['done', 'failed']}
            replayBudget={{ cap: 0.5, spent_today: 0, cap_reached: false }}
            athleteId={7}
            currency="USD"
            {...overrides}
        />,
    );
}

beforeEach(() => {
    vi.mocked(router.get).mockClear();
});

describe('narrationsHref', () => {
    it('carries the active filters and the cursor', () => {
        expect(
            narrationsHref(7, { kind: 'trend_read', status: 'done' }, 42),
        ).toBe(
            '/devtools/narration/athletes/7?tab=narrations&kind=trend_read&status=done&before=42',
        );
    });

    it('drops the cursor and any unset filter', () => {
        expect(narrationsHref(7, { kind: null, status: null })).toBe(
            '/devtools/narration/athletes/7?tab=narrations',
        );
    });
});

describe('NarrationsTab', () => {
    it('renders the rows it was given', () => {
        renderTab();

        expect(screen.getByText('a read.')).toBeInTheDocument();
    });

    it('navigates on a kind change, dropping the cursor', () => {
        renderTab({ filters: { kind: null, status: 'done', before: 40 } });

        fireEvent.change(screen.getByLabelText('kind'), {
            target: { value: 'weekly_recap' },
        });

        expect(router.get).toHaveBeenCalledWith(
            '/devtools/narration/athletes/7?tab=narrations&kind=weekly_recap&status=done',
        );
    });

    it('navigates back to all on clearing the status filter', () => {
        renderTab({ filters: { kind: null, status: 'done', before: null } });

        fireEvent.change(screen.getByLabelText('status'), {
            target: { value: '' },
        });

        expect(router.get).toHaveBeenCalledWith(
            '/devtools/narration/athletes/7?tab=narrations',
        );
    });

    it('offers an older link only when a cursor came back', () => {
        renderTab();
        expect(screen.queryByText('older')).not.toBeInTheDocument();

        renderTab({ nextCursor: 11 });
        expect(screen.getByText('older').getAttribute('href')).toBe(
            '/devtools/narration/athletes/7?tab=narrations&before=11',
        );
    });

    it('shows an empty state when nothing matches', () => {
        renderTab({ narrations: [] });

        expect(screen.getByText('no narrations here')).toBeInTheDocument();
    });
});
