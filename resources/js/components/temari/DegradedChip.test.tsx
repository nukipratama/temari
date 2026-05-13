import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import DegradedChip from './DegradedChip';

describe('DegradedChip', () => {
    it('renders the "mode darurat" label', () => {
        render(<DegradedChip />);
        expect(screen.getByText(/mode darurat/i)).toBeInTheDocument();
    });
});
