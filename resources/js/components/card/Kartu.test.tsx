import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Kartu from './Kartu';
import type { Rarity } from '@/types/inertia';

describe('Kartu', () => {
    it('renders name + stats', () => {
        render(<Kartu name="Pejuang Subuh" km="8.4" durasi="42:11" trimp={68} />);
        expect(screen.getByText('Pejuang Subuh')).toBeInTheDocument();
        expect(screen.getByText('8.4')).toBeInTheDocument();
        expect(screen.getByText('42:11')).toBeInTheDocument();
        expect(screen.getByText('68')).toBeInTheDocument();
    });

    it.each(['common', 'uncommon', 'rare', 'epic', 'legendary'] satisfies Rarity[])(
        'renders rarity flag for %s',
        (rarity) => {
            render(<Kartu name="x" km="1" durasi="1:00" trimp={1} rarity={rarity} />);
            const label = { common: 'Biasa', uncommon: 'Berkesan', rare: 'Langka', epic: 'Luar Biasa', legendary: 'Legendaris' }[rarity];
            expect(screen.getByText(label)).toBeInTheDocument();
        },
    );

    it('renders tags as horizon chips', () => {
        render(<Kartu name="x" km="1" durasi="1:00" trimp={1} tags={['Negative Split']} />);
        expect(screen.getByText('Negative Split')).toBeInTheDocument();
    });
});
