import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import SplitsTable from './SplitsTable';
import type { StreamSummaryPartial, StreamSummaryPerKm } from '@/types/inertia';

const rows: StreamSummaryPerKm[] = [
    { km: 1, pace: '6:00', avg_hr: 150, avg_cadence_spm: 170 },
    { km: 2, pace: '5:45', avg_hr: 155, avg_cadence_spm: 173 },
];

const partial: StreamSummaryPartial = { distance_m: 700, pace: '4:00', avg_hr: 158, avg_cadence_spm: 168 };

describe('SplitsTable', () => {
    it('renders the section header and crowns the fastest km', () => {
        render(<SplitsTable rows={rows} />);
        expect(screen.getByText('Splits per km')).toBeInTheDocument();
        expect(screen.getByText(/Paling kenceng di km 2/)).toBeInTheDocument();
        expect(screen.getByText('5:45/km')).toBeInTheDocument();
    });

    it('renders one row per km with its HR and cadence cells', () => {
        render(<SplitsTable rows={rows} />);
        expect(screen.getByText('KM 1')).toBeInTheDocument();
        expect(screen.getByText('KM 2')).toBeInTheDocument();
        expect(screen.getByText('♡ 150')).toBeInTheDocument();
        expect(screen.getByText('↻ 173')).toBeInTheDocument();
    });

    it('tints the fastest row and zebra-stripes the rest', () => {
        const { container } = render(<SplitsTable rows={rows} />);
        expect(container.querySelector('.bg-horizon\\/\\[0\\.08\\]')).not.toBeNull();
        expect(container.querySelector('.bg-sky\\/\\[0\\.03\\]')).not.toBeNull();
    });

    it('zebra-stripes an odd row that is not the fastest km', () => {
        const { container } = render(
            <SplitsTable rows={[{ km: 1, pace: '5:45' }, { km: 2, pace: '6:00' }]} />,
        );
        expect(container.querySelector('.bg-cream-deep\\/30')).not.toBeNull();
    });

    it('omits the crown line when no split has a parseable pace', () => {
        render(<SplitsTable rows={[{ km: 1, pace: 'n/a' }]} />);
        expect(screen.getByText('Splits per km')).toBeInTheDocument();
        expect(screen.queryByText(/Paling kenceng/)).not.toBeInTheDocument();
    });

    it('keys full splits by km and the trailing partial positionally', () => {
        // A row without a km (legacy/corrupt JSON) must still get a collision-proof
        // key rather than colliding with another km-less row.
        const { container } = render(
            <SplitsTable rows={[{ km: null as unknown as number, pace: '6:00' }, ...rows]} />,
        );
        expect(screen.getByText('KM ?')).toBeInTheDocument();
        expect(container.querySelectorAll('.rounded-lg').length).toBeGreaterThan(0);
    });

    it('renders a marked "sisa" partial row without crowning it fastest', () => {
        // A fast sisa (4:00) must not steal the "fastest km" crown from km 2 (5:45).
        render(<SplitsTable rows={rows} partial={partial} />);
        expect(screen.getByText('0.7 KM')).toBeInTheDocument();
        expect(screen.getByText(/putus-putus = sisa/)).toBeInTheDocument();
        expect(screen.getByText(/Paling kenceng di km 2/)).toBeInTheDocument();
        expect(screen.getByText('♡ 158')).toBeInTheDocument();
        expect(screen.getByText('↻ 168')).toBeInTheDocument();
    });

    it('drops the "sisa" note from the legend when there is no partial', () => {
        render(<SplitsTable rows={rows} />);
        expect(screen.queryByText(/putus-putus = sisa/)).not.toBeInTheDocument();
    });

    it('still renders for a sub-1km run that has only a partial', () => {
        render(<SplitsTable rows={[]} partial={{ distance_m: 800, pace: '5:00' }} />);
        expect(screen.getByText('Splits per km')).toBeInTheDocument();
        expect(screen.getByText('0.8 KM')).toBeInTheDocument();
        // No HR/cadence on this partial: dashes, not blanks.
        expect(screen.getAllByText(/[♡↻] —/).length).toBe(2);
    });

    it('passes the className through to the card', () => {
        const { container } = render(<SplitsTable rows={rows} className="mt-10" />);
        expect(container.querySelector('section')).toHaveClass('mt-10');
    });
});
