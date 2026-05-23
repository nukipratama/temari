import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import VoiceCard from './VoiceCard';

describe('VoiceCard', () => {
    it('wraps content in italicised serif quote marks', () => {
        render(<VoiceCard>Lari pagi yang oke</VoiceCard>);
        expect(screen.getByText(/Lari pagi yang oke/)).toBeInTheDocument();
    });

    it('renders attribution + pose', () => {
        render(<VoiceCard attribution="Temari" pose="proud">Halo</VoiceCard>);
        expect(screen.getByText(/Temari/)).toBeInTheDocument();
        expect(screen.getByText(/proud/)).toBeInTheDocument();
    });

    it('switches text colour to cream on sky', () => {
        const { container } = render(<VoiceCard onSky>Halo</VoiceCard>);
        const quote = container.querySelector('p');
        expect(quote?.className).toMatch(/text-cream/);
    });
});
