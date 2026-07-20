import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import RiwayatFilter, { type MoodOption } from './RiwayatFilter';

type Range = '8w' | '12w';

const RANGE_OPTIONS = [
    { value: '8w' as const, label: '8 minggu' },
    { value: '12w' as const, label: '12 minggu', hint: 'default' },
];

const MOOD_OPTIONS: ReadonlyArray<MoodOption> = [
    { mood: 'nyala', label: 'Nyala', hint: 'pr', swatchClass: 'bg-mood-nyala' },
    { mood: 'lemes', label: 'Lemes', hint: 'strain', swatchClass: 'bg-mood-lemes' },
];

function openPanel() {
    fireEvent.click(screen.getByRole('button', { name: /buka filter/i }));
}

describe('RiwayatFilter', () => {
    it('renders range options as links to hrefFor() once opened', () => {
        render(
            <RiwayatFilter<Range>
                range={{ value: '12w', options: RANGE_OPTIONS, hrefFor: (v) => `/aktivitas?range=${v}`, only: ['runs'] }}
            />,
        );
        openPanel();

        const link = screen.getByRole('link', { name: /8 minggu/i });
        expect(link).toHaveAttribute('href', '/aktivitas?range=8w');
        // The active option is marked, not duplicated as a link target.
        expect(screen.getByRole('link', { name: /12 minggu/i })).toHaveAttribute('aria-current', 'true');
    });

    it('toggles a mood and fires onReset', () => {
        const onToggle = vi.fn();
        const onReset = vi.fn();
        render(
            <RiwayatFilter
                mood={{ selected: new Set(['nyala']), options: MOOD_OPTIONS, onToggle }}
                onReset={onReset}
            />,
        );
        openPanel();

        fireEvent.click(screen.getByRole('button', { name: /lemes/i }));
        expect(onToggle).toHaveBeenCalledWith('lemes');

        fireEvent.click(screen.getByRole('button', { name: /reset/i }));
        expect(onReset).toHaveBeenCalled();
    });

    it('marks the selected mood as pressed, not menu-checked', () => {
        render(
            <RiwayatFilter mood={{ selected: new Set(['nyala']), options: MOOD_OPTIONS, onToggle: vi.fn() }} />,
        );
        openPanel();

        expect(screen.getByRole('button', { name: /nyala/i })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('button', { name: /lemes/i })).toHaveAttribute('aria-pressed', 'false');
    });

    it('does not adopt ARIA menu semantics (disclosure popover, not a menu)', () => {
        render(
            <RiwayatFilter<Range>
                range={{ value: '12w', options: RANGE_OPTIONS, hrefFor: (v) => `/aktivitas?range=${v}` }}
                mood={{ selected: new Set(), options: MOOD_OPTIONS, onToggle: vi.fn() }}
            />,
        );
        openPanel();

        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
        expect(screen.queryByRole('menuitemradio')).not.toBeInTheDocument();
        expect(screen.queryByRole('menuitemcheckbox')).not.toBeInTheDocument();
    });

    it('returns focus to the trigger button when Escape is pressed', () => {
        render(
            <RiwayatFilter<Range>
                range={{ value: '12w', options: RANGE_OPTIONS, hrefFor: (v) => `/aktivitas?range=${v}` }}
            />,
        );
        const trigger = screen.getByRole('button', { name: /buka filter/i });
        openPanel();
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(document.activeElement).toBe(trigger);
    });

    describe('distance section', () => {
        const DISTANCE_OPTIONS = [
            { value: '0-5' as const, label: 'Di bawah 5K', hint: '<5' },
            { value: '21up' as const, label: 'Half ke atas', hint: '21+' },
        ];

        it('selects a band and marks the active one', () => {
            const onSelect = vi.fn();
            render(
                <RiwayatFilter<Range, '0-5' | '21up'>
                    distance={{ value: '21up', options: DISTANCE_OPTIONS, onSelect }}
                />,
            );
            openPanel();

            expect(screen.getByRole('button', { name: /Half ke atas/ })).toHaveAttribute('aria-pressed', 'true');
            fireEvent.click(screen.getByRole('button', { name: /Di bawah 5K/ }));
            expect(onSelect).toHaveBeenCalledWith('0-5');
        });

        // Re-selecting the active band is how you clear it, so the popover needs
        // no separate "any distance" row.
        it('reports the same band again so the page can clear it', () => {
            const onSelect = vi.fn();
            render(
                <RiwayatFilter<Range, '0-5' | '21up'>
                    distance={{ value: '21up', options: DISTANCE_OPTIONS, onSelect }}
                />,
            );
            openPanel();
            fireEvent.click(screen.getByRole('button', { name: /Half ke atas/ }));
            expect(onSelect).toHaveBeenCalledWith('21up');
        });
    });

    describe('search section', () => {
        it('submits the term on Enter', () => {
            const onSubmit = vi.fn();
            render(<RiwayatFilter<Range> search={{ value: '', onSubmit }} />);
            openPanel();

            const input = screen.getByLabelText('Cari nama lari');
            fireEvent.change(input, { target: { value: 'tempo' } });
            fireEvent.keyDown(input, { key: 'Enter' });

            expect(onSubmit).toHaveBeenCalledWith('tempo');
        });

        // Each submit is a server round trip, so it fires on commit (Enter/blur),
        // never per keystroke.
        it('does not submit while the user is still typing', () => {
            const onSubmit = vi.fn();
            render(<RiwayatFilter<Range> search={{ value: '', onSubmit }} />);
            openPanel();

            fireEvent.change(screen.getByLabelText('Cari nama lari'), { target: { value: 'tem' } });
            expect(onSubmit).not.toHaveBeenCalled();
        });

        it('submits on blur when the term changed', () => {
            const onSubmit = vi.fn();
            render(<RiwayatFilter<Range> search={{ value: 'old', onSubmit }} />);
            openPanel();

            const input = screen.getByLabelText('Cari nama lari');
            fireEvent.change(input, { target: { value: 'new' } });
            fireEvent.blur(input);

            expect(onSubmit).toHaveBeenCalledWith('new');
        });

        it('does not re-submit on blur when the term is unchanged', () => {
            const onSubmit = vi.fn();
            render(<RiwayatFilter<Range> search={{ value: 'same', onSubmit }} />);
            openPanel();

            fireEvent.blur(screen.getByLabelText('Cari nama lari'));
            expect(onSubmit).not.toHaveBeenCalled();
        });

        it('re-syncs the input when the server reports a different term', () => {
            const { rerender } = render(<RiwayatFilter<Range> search={{ value: 'tempo', onSubmit: vi.fn() }} />);
            openPanel();
            expect(screen.getByLabelText('Cari nama lari')).toHaveValue('tempo');

            // e.g. Reset was hit, clearing the term server-side.
            rerender(<RiwayatFilter<Range> search={{ value: '', onSubmit: vi.fn() }} />);
            expect(screen.getByLabelText('Cari nama lari')).toHaveValue('');
        });
    });

    it('counts every active filter on the trigger badge', () => {
        render(
            <RiwayatFilter<Range, '0-5'>
                // '12w' is not the first option, so the range counts as active.
                range={{ value: '12w', options: RANGE_OPTIONS, hrefFor: (v) => `/aktivitas?range=${v}` }}
                mood={{ selected: new Set(['nyala']), options: MOOD_OPTIONS, onToggle: vi.fn() }}
                distance={{ value: '0-5', options: [{ value: '0-5', label: 'Di bawah 5K' }], onSelect: vi.fn() }}
                search={{ value: 'tempo', onSubmit: vi.fn() }}
            />,
        );

        // range + 1 mood + distance + search
        expect(screen.getByRole('button', { name: /buka filter/i })).toHaveTextContent('4');
    });

    // The first range option is the implicit default, so sitting on it is not a
    // filter the user has to be told about.
    it('does not count the default range towards the badge', () => {
        render(
            <RiwayatFilter<Range>
                range={{ value: '8w', options: RANGE_OPTIONS, hrefFor: (v) => `/aktivitas?range=${v}` }}
                search={{ value: 'tempo', onSubmit: vi.fn() }}
            />,
        );

        expect(screen.getByRole('button', { name: /buka filter/i })).toHaveTextContent('1');
    });
});
