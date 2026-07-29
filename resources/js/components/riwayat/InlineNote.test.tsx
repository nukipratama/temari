import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import InlineNote, { RangeWidenedNote, RunsTruncatedNote, WeekFocusNote } from './InlineNote';

describe('InlineNote', () => {
    it('renders the icon and the sentence', () => {
        const { container } = render(<InlineNote icon="mdi:history">Ada yang disembunyiin.</InlineNote>);

        expect(screen.getByText('Ada yang disembunyiin.')).toBeInTheDocument();
        expect(container.querySelector('[data-icon="mdi:history"]')).not.toBeNull();
    });

    it('renders no trailing control when the note offers no way out', () => {
        render(<InlineNote icon="mdi:history">Cuma sebaris.</InlineNote>);

        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('renders the action beside the sentence when one is given', () => {
        render(
            <InlineNote icon="mdi:history" action={<a href="/aktivitas">Keluar</a>}>
                Cuma sebaris.
            </InlineNote>,
        );

        expect(screen.getByRole('link', { name: 'Keluar' })).toBeInTheDocument();
    });
});

describe('RunsTruncatedNote', () => {
    it('names the per-page cap that dropped the older runs', () => {
        render(<RunsTruncatedNote maxRuns={365} />);

        expect(screen.getByText(/Menampilkan 365 lari terbaru/)).toBeInTheDocument();
    });
});

describe('RangeWidenedNote', () => {
    it('names the range the server widened to', () => {
        render(<RangeWidenedNote rangeFilter="1y" />);

        expect(screen.getByText(/Rentang diperlebar otomatis ke Setahun penuh/)).toBeInTheDocument();
    });

    // Widened all the way there is no "range" left to name, so the copy changes.
    it('says it is showing everything when widened to the full history', () => {
        render(<RangeWidenedNote rangeFilter="all" />);

        expect(screen.getByText(/Menampilkan semua lari kamu/)).toBeInTheDocument();
    });
});

describe('WeekFocusNote', () => {
    // Reached from the weekly-recap notification. Without the note the view
    // looks like a history that mysteriously lost most of its runs.
    it('names the Monday-to-Sunday window and offers a way back to the full list', () => {
        render(<WeekFocusNote weekEnding="2026-05-17" />);

        expect(screen.getByText(/Lagi lihat minggu Senin, 11 Mei - Minggu, 17 Mei/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Lihat semua lari/ })).toHaveAttribute('href', '/aktivitas');
    });
});
