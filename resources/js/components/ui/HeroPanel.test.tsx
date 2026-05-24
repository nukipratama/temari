import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import HeroPanel from './HeroPanel';

describe('HeroPanel', () => {
    it('renders children', () => {
        const { getByText } = render(
            <HeroPanel>
                <span>Selamat pagi</span>
            </HeroPanel>,
        );
        expect(getByText('Selamat pagi')).toBeInTheDocument();
    });

    it('uses solid sky bg when gradient=false', () => {
        const { container } = render(<HeroPanel gradient={false}>x</HeroPanel>);
        expect(container.firstChild).toHaveClass('bg-sky');
    });
});
