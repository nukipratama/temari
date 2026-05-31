import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import SectionLabel from './SectionLabel';

describe('SectionLabel', () => {
    it('renders children + rule', () => {
        const { container } = render(<SectionLabel>Hari ini</SectionLabel>);
        expect(screen.getByText('Hari ini')).toBeInTheDocument();
        expect(container.querySelectorAll('span').length).toBe(2);
    });

    it('switches to the muted ink-on-sky text on sky', () => {
        const { container } = render(<SectionLabel onSky>x</SectionLabel>);
        expect(container.firstChild).toHaveClass(/text-ink-on-sky/);
    });
});
