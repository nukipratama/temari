import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import StatTile from './StatTile';

describe('StatTile', () => {
    it('renders value + label', () => {
        render(<StatTile value="12.5" label="KM" />);
        expect(screen.getByText('12.5')).toBeInTheDocument();
        expect(screen.getByText('KM')).toBeInTheDocument();
    });

    it('renders sub line when provided', () => {
        render(<StatTile value="100" label="TRIMP" sub="Beban sedang" />);
        expect(screen.getByText('Beban sedang')).toBeInTheDocument();
    });

    it.each(['cream', 'sky', 'creamDeep'] as const)('renders tone %s', (tone) => {
        render(<StatTile value="x" label="y" tone={tone} />);
        expect(screen.getByText('x')).toBeInTheDocument();
    });
});
