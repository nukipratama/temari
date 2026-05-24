import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import KartuMini from './KartuMini';
import type { Rarity } from '@/types/inertia';

describe('KartuMini', () => {
    it('renders name + date', () => {
        render(<KartuMini name="Sunset 5K" date="18 Mei" />);
        expect(screen.getByText('Sunset 5K')).toBeInTheDocument();
        expect(screen.getByText('18 Mei')).toBeInTheDocument();
    });

    it.each(['common', 'uncommon', 'rare', 'epic', 'legendary'] satisfies Rarity[])(
        'renders for rarity %s',
        (rarity) => {
            render(<KartuMini name="x" rarity={rarity} />);
            expect(screen.getByText('x')).toBeInTheDocument();
        },
    );
});
