import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import SplitsSparkline from './SplitsSparkline';

describe('SplitsSparkline', () => {
    it('renders the empty-state copy when paceSec is empty', () => {
        render(<SplitsSparkline paceSec={[]} />);
        expect(screen.getByText(/Splits aren't available/)).toBeInTheDocument();
    });

    it('labels the run as negative-split when last km is faster than first', () => {
        render(<SplitsSparkline paceSec={[380, 360, 350, 345]} />);
        expect(screen.getByText(/clean negative split/)).toBeInTheDocument();
    });

    it('labels stable splits when last is not faster than first', () => {
        render(<SplitsSparkline paceSec={[350, 360, 355, 360]} />);
        expect(screen.getByText(/steady splits/)).toBeInTheDocument();
    });

    it('renders one bar per km with aria-labels', () => {
        render(<SplitsSparkline paceSec={[360, 350, 345]} />);
        expect(screen.getByLabelText(/Km 1:/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Km 3:/)).toBeInTheDocument();
    });

    it('buckets long runs into averaged segments instead of one bar per km', () => {
        const marathon = Array.from({ length: 42 }, (_, i) => 460 + i);
        render(<SplitsSparkline paceSec={marathon} />);
        // 42 km → ceil(42/16)=3 km per bucket → range-labelled bars, not per-km.
        expect(screen.getByText(/avg per 3 km/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Km 1–3:/)).toBeInTheDocument();
        // No single-km label like "Km 5:" should exist once bucketed.
        expect(screen.queryByLabelText(/Km 5:/)).not.toBeInTheDocument();
    });

    it('renders a de-emphasized "remainder" ghost bar for a trailing partial', () => {
        render(<SplitsSparkline paceSec={[360, 350]} partialPaceSec={300} />);
        expect(screen.getByText('remainder')).toBeInTheDocument();
        expect(screen.getByLabelText(/Remainder:/)).toBeInTheDocument();
    });

    it('keeps the partial out of the verdict and crown (a fast remainder never flips it)', () => {
        // Full km are stable (last not faster than first); a very fast partial
        // must not turn the verdict negative or steal the "best" bar.
        render(<SplitsSparkline paceSec={[350, 360]} partialPaceSec={200} />);
        expect(screen.getByText(/steady splits/)).toBeInTheDocument();
        expect(
            screen.queryByText(/clean negative split/),
        ).not.toBeInTheDocument();
    });

    it('shows no ghost bar when there is no partial', () => {
        render(<SplitsSparkline paceSec={[360, 350]} partialPaceSec={null} />);
        expect(screen.queryByText('remainder')).not.toBeInTheDocument();
    });
});
