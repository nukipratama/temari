import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import KpiTile from './KpiTile';

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

    it.each(['neutral', 'positive', 'warning', 'alert'] as const)('applies tone %s without error', (tone) => {
        render(<KpiTile label="L" value="V" tone={tone} />);
    });
});
