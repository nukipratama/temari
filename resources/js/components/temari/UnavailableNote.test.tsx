import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import UnavailableNote from './UnavailableNote';

describe('UnavailableNote', () => {
    it('shows the default message with a status role', () => {
        render(<UnavailableNote />);
        expect(screen.getByRole('status')).toHaveTextContent(
            'Temari is taking a moment. Try again shortly.',
        );
    });

    it('shows a custom message when given', () => {
        render(<UnavailableNote message="No data for this yet." />);
        expect(screen.getByText('No data for this yet.')).toBeInTheDocument();
    });

    it('applies the sm size classes when size="sm"', () => {
        render(<UnavailableNote size="sm" />);
        expect(screen.getByRole('status').className).toContain('text-xs');
    });
});
