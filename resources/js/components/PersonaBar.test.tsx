import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import PersonaBar from './PersonaBar';

describe('PersonaBar', () => {
    it('shows the nudge message when there is no mix data', () => {
        render(<PersonaBar mix={[]} />);
        expect(screen.getByText('Belum ada cukup lari buat baca personamu.')).toBeInTheDocument();
    });

    it('renders a legend entry per mood with its label and percent', () => {
        render(
            <PersonaBar
                mix={[
                    { mood: 'nyala', count: 3, percent: 60 },
                    { mood: 'adem', count: 2, percent: 40 },
                ]}
            />,
        );
        expect(screen.getByText('Nyala')).toBeInTheDocument();
        expect(screen.getByText('60.0%')).toBeInTheDocument();
        expect(screen.getByText('Adem')).toBeInTheDocument();
        expect(screen.getByText('40.0%')).toBeInTheDocument();
    });

    it('sizes each bar segment by its percent', () => {
        const { container } = render(
            <PersonaBar mix={[{ mood: 'enteng', count: 1, percent: 25 }]} />,
        );
        const segment = container.querySelector('[aria-label="Enteng 25%"]');
        expect(segment).toHaveStyle({ width: '25%' });
    });
});
