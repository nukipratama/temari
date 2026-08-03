import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { KindOption, RangeToken } from '@/pages/AiUsage/types';

import UsageFilters from './UsageFilters';

const availableKinds: KindOption[] = [
    { value: 'briefing', label: 'BriefingMascotVoice' },
    { value: 'run-insight', label: 'RunInsightTechnical' },
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
            availableKinds={availableKinds}
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

        expect(screen.queryByText(/Filter:/)).not.toBeInTheDocument();
    });

    it('names the active kind filter in the readout', () => {
        renderFilters({ kind: 'briefing' });

        expect(screen.getByText(/Filter:/)).toBeInTheDocument();
        expect(screen.getByText('briefing')).toBeInTheDocument();
    });

    it('submits the date fields as a custom window', () => {
        renderFilters();

        fireEvent.click(screen.getByRole('button', { name: /terapkan/i }));

        expect(router.get).toHaveBeenCalledWith(
            '/ai-usage',
            { from: '2026-05-01', to: '2026-05-19' },
            { preserveState: true, preserveScroll: true },
        );
    });

    it('sends the kind filter along with a submitted custom window', () => {
        renderFilters({ kind: 'briefing' });

        fireEvent.click(screen.getByRole('button', { name: /terapkan/i }));

        expect(router.get).toHaveBeenCalledWith(
            '/ai-usage',
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

        fireEvent.change(screen.getByLabelText(/jenis/i), {
            target: { value: 'briefing' },
        });

        expect(router.get).toHaveBeenCalledWith(
            '/ai-usage',
            { range: '7d', kind: 'briefing' },
            { preserveState: true, preserveScroll: true },
        );
    });

    it('clearing the kind filter drops it from the query rather than sending an empty one', () => {
        renderFilters({ range: '7d' as RangeToken, kind: 'briefing' });

        fireEvent.change(screen.getByLabelText(/jenis/i), {
            target: { value: '' },
        });

        expect(router.get).toHaveBeenCalledWith(
            '/ai-usage',
            { range: '7d' },
            { preserveState: true, preserveScroll: true },
        );
    });

    it('hides the kind dropdown when the report has no kinds to filter by', () => {
        renderFilters({ availableKinds: [] });

        expect(screen.queryByLabelText(/jenis/i)).not.toBeInTheDocument();
    });

    it.each([
        ['hari ini', /hari ini/i, 'today'],
        ['7 hari', /7 hari/i, '7d'],
        ['30 hari', /30 hari/i, '30d'],
        ['bulan ini', /bulan ini/i, 'month'],
        ['semua', /semua/i, 'all'],
    ])(
        'preset "%s" links to a date-free range token (durable, never stale)',
        (_label, pattern, token) => {
            renderFilters();

            expect(
                screen
                    .getByRole('link', { name: pattern })
                    .getAttribute('href'),
            ).toBe(`/ai-usage?range=${token}`);
        },
    );

    it('preset links preserve the active kind filter', () => {
        renderFilters({ kind: 'briefing' });

        expect(
            screen.getByRole('link', { name: /7 hari/i }).getAttribute('href'),
        ).toBe('/ai-usage?range=7d&kind=briefing');
    });

    it('highlights the active preset', () => {
        renderFilters({ range: '7d' as RangeToken });

        expect(
            screen.getByRole('link', { name: /7 hari/i }).className,
        ).toContain('bg-sky');
        expect(
            screen.getByRole('link', { name: /30 hari/i }).className,
        ).toContain('bg-cream-deep');
    });
});
