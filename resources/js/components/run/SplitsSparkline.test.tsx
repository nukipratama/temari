import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import SplitsSparkline from './SplitsSparkline';

describe('SplitsSparkline', () => {
    it('renders the empty-state copy when paceSec is empty', () => {
        render(<SplitsSparkline paceSec={[]} />);
        expect(screen.getByText(/Splits belum tersedia/)).toBeInTheDocument();
    });

    it('labels the run as negative-split when last km is faster than first', () => {
        render(<SplitsSparkline paceSec={[380, 360, 350, 345]} />);
        expect(screen.getByText(/negatif-split/)).toBeInTheDocument();
    });

    it('labels stable splits when last is not faster than first', () => {
        render(<SplitsSparkline paceSec={[350, 360, 355, 360]} />);
        expect(screen.getByText(/splits stabil/)).toBeInTheDocument();
    });

    it('renders one bar per km with aria-labels', () => {
        render(<SplitsSparkline paceSec={[360, 350, 345]} />);
        expect(screen.getByLabelText(/Km 1:/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Km 3:/)).toBeInTheDocument();
    });
});
