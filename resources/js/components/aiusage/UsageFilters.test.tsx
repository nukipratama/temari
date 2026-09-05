import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { KindOption, RangeToken } from '@/pages/AiUsage/types';

import UsageFilters from './UsageFilters';

const availableKinds: KindOption[] = [
    { value: 'briefing', label: 'BriefingMascotVoice' },
    { value: 'run-insight', label: 'RunInsightTechnical' },
];

const availableOrigins: KindOption[] = [
    { value: 'ingest', label: 'Ingest cascade' },
    { value: 'user', label: 'User-initiated' },
];

function renderFilters(
    overrides: Partial<Parameters<typeof UsageFilters>[0]> = {},
) {
    return render(
        <UsageFilters
            range={'custom' as RangeToken}
            from="2026-05-01"
            to="2026-05-19"
            kind={null}
            origin={null}
            availableKinds={availableKinds}
            availableOrigins={availableOrigins}
            {...overrides}
        />,
    );
}

beforeEach(() => {
    vi.mocked(router.get).mockClear();
});

describe('UsageFilters', () => {
    it('reads back the active range', () => {
        renderFilters();

        expect(screen.getByText('2026-05-01')).toBeInTheDocument();
        expect(screen.getByText('2026-05-19')).toBeInTheDocument();
    });

    it('leaves the kind out of the readout when no filter is active', () => {
        renderFilters();

        expect(screen.queryByText(/Kind:/)).not.toBeInTheDocument();
    });

    it('names the active kind filter in the readout', () => {
        renderFilters({ kind: 'briefing' });

        expect(screen.getByText(/Kind:/)).toBeInTheDocument();
        expect(screen.getByText('briefing')).toBeInTheDocument();
    });

    it('submits the date fields as a custom window', () => {
        renderFilters();

        fireEvent.click(screen.getByRole('button', { name: /apply/i }));

        expect(router.get).toHaveBeenCalledWith(
            '/devtools/ai-usage',
            { from: '2026-05-01', to: '2026-05-19' },
            { preserveState: true, preserveScroll: true },
        );
    });

    it('sends the kind filter along with a submitted custom window', () => {
        renderFilters({ kind: 'briefing' });

        fireEvent.click(screen.getByRole('button', { name: /apply/i }));

        expect(router.get).toHaveBeenCalledWith(
            '/devtools/ai-usage',
            { from: '2026-05-01', to: '2026-05-19', kind: 'briefing' },
            { preserveState: true, preserveScroll: true },
        );
    });

    it('typing into a date field updates the form value', () => {
        const { container } = renderFilters();
        const fromInput = container.querySelector('#from') as HTMLInputElement;

        fireEvent.change(fromInput, { target: { value: '2026-04-15' } });

        expect(fromInput.value).toBe('2026-04-15');
    });

    it('typing into the "to" field updates the form value', () => {
        const { container } = renderFilters();
        const toInput = container.querySelector('#to') as HTMLInputElement;

        fireEvent.change(toInput, { target: { value: '2026-06-01' } });

        expect(toInput.value).toBe('2026-06-01');
    });

    it('applies the kind filter immediately on change, preserving the range', () => {
        renderFilters({ range: '7d' as RangeToken });

        fireEvent.change(screen.getByLabelText(/kind/i), {
            target: { value: 'briefing' },
        });

        expect(router.get).toHaveBeenCalledWith(
            '/devtools/ai-usage',
            { range: '7d', kind: 'briefing' },
            { preserveState: true, preserveScroll: true },
        );
    });

    it('clearing the kind filter drops it from the query rather than sending an empty one', () => {
        renderFilters({ range: '7d' as RangeToken, kind: 'briefing' });

        fireEvent.change(screen.getByLabelText(/kind/i), {
            target: { value: '' },
        });

        expect(router.get).toHaveBeenCalledWith(
            '/devtools/ai-usage',
            { range: '7d' },
            { preserveState: true, preserveScroll: true },
        );
    });

    it('hides the kind dropdown when the report has no kinds to filter by', () => {
        renderFilters({ availableKinds: [] });

        expect(screen.queryByLabelText(/kind/i)).not.toBeInTheDocument();
    });

    it.each([
        ['Today', /today/i, 'today'],
        ['7 days', /7 days/i, '7d'],
        ['30 days', /30 days/i, '30d'],
        ['This month', /this month/i, 'month'],
        ['All', /all/i, 'all'],
    ])(
        'preset "%s" links to a date-free range token (durable, never stale)',
        (_label, pattern, token) => {
            renderFilters();

            expect(
                screen
                    .getByRole('link', { name: pattern })
                    .getAttribute('href'),
            ).toBe(`/devtools/ai-usage?range=${token}`);
        },
    );

    it('preset links preserve the active kind filter', () => {
        renderFilters({ kind: 'briefing' });

        expect(
            screen.getByRole('link', { name: /7 days/i }).getAttribute('href'),
        ).toBe('/devtools/ai-usage?range=7d&kind=briefing');
    });

    it('highlights the active preset', () => {
        renderFilters({ range: '7d' as RangeToken });

        expect(screen.getByRole('link', { name: /7 days/i })).toHaveClass(
            'bg-foreground',
        );
        expect(screen.getByRole('link', { name: /30 days/i })).toHaveClass(
            'bg-muted',
        );
    });
});

describe('origin filter', () => {
    it('offers every origin present in the range', () => {
        renderFilters();

        expect(screen.getByLabelText('Origin')).toBeInTheDocument();
        expect(
            screen.getByRole('option', { name: 'Ingest cascade' }),
        ).toBeInTheDocument();
    });

    it('applies immediately and keeps the active range window', () => {
        renderFilters({ range: '7d' as RangeToken });

        fireEvent.change(screen.getByLabelText('Origin'), {
            target: { value: 'ingest' },
        });

        expect(router.get).toHaveBeenCalledWith(
            '/devtools/ai-usage',
            { range: '7d', origin: 'ingest' },
            { preserveState: true, preserveScroll: true },
        );
    });

    it('carries the active kind filter alongside the origin', () => {
        renderFilters({ range: '7d' as RangeToken, kind: 'briefing' });

        fireEvent.change(screen.getByLabelText('Origin'), {
            target: { value: 'user' },
        });

        expect(router.get).toHaveBeenCalledWith(
            '/devtools/ai-usage',
            { range: '7d', kind: 'briefing', origin: 'user' },
            { preserveState: true, preserveScroll: true },
        );
    });

    it('clears back to every origin', () => {
        renderFilters({ range: '7d' as RangeToken, origin: 'ingest' });

        fireEvent.change(screen.getByLabelText('Origin'), {
            target: { value: '' },
        });

        expect(router.get).toHaveBeenCalledWith(
            '/devtools/ai-usage',
            { range: '7d' },
            { preserveState: true, preserveScroll: true },
        );
    });
});
