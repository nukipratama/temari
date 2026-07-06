import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import KpiTile from './KpiTile';
import type { Tone } from '@/types/inertia';

// Not exported from KpiTile.tsx — mirrored here so the test can assert the
// actual tone class landed, not just that rendering didn't throw.
const TONE_CLASS: Record<Tone, string> = {
    positive: 'text-mood-enteng',
    warning: 'text-mood-nyala',
    alert: 'text-mood-lemes',
    neutral: 'text-ink',
};

describe('KpiTile', () => {
    it('renders label + value', () => {
        render(<KpiTile label="Form" value="42.0" />);
        expect(screen.getByText('Form')).toBeInTheDocument();
        expect(screen.getByText('42.0')).toBeInTheDocument();
    });

    it('renders optional sub line when provided', () => {
        render(<KpiTile label="L" value="V" sub="sub text" />);
        expect(screen.getByText('sub text')).toBeInTheDocument();
    });

    it('omits sub when null', () => {
        render(<KpiTile label="L" value="V" sub={null} />);
        expect(screen.queryByText('sub text')).not.toBeInTheDocument();
    });

    it.each(['neutral', 'positive', 'warning', 'alert'] as const)('applies the %s tone class to the value', (tone) => {
        render(<KpiTile label="L" value="V" tone={tone} />);
        expect(screen.getByText('V')).toHaveClass(TONE_CLASS[tone]);
    });
});
