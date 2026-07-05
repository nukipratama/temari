import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import UnavailableNote from './UnavailableNote';

describe('UnavailableNote', () => {
    it('shows the default message with a status role', () => {
        render(<UnavailableNote />);
        expect(screen.getByRole('status')).toHaveTextContent('Temari lagi diem dulu. Coba lagi sebentar.');
    });

    it('shows a custom message when given', () => {
        render(<UnavailableNote message="Belum ada data buat ini." />);
        expect(screen.getByText('Belum ada data buat ini.')).toBeInTheDocument();
    });

    it('applies the sm size classes when size="sm"', () => {
        render(<UnavailableNote size="sm" />);
        expect(screen.getByRole('status').className).toContain('text-xs');
    });
});
