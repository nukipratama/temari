import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Chip, { type ChipTone } from './Chip';

describe('Chip', () => {
    it.each(['neutral', 'horizon', 'leaf', 'sky', 'onSky'] satisfies ChipTone[])(
        'renders tone %s',
        (tone) => {
            render(<Chip tone={tone}>label</Chip>);
            expect(screen.getByText('label')).toBeInTheDocument();
        },
    );

    it('uses md sizing when size="md"', () => {
        render(<Chip size="md">x</Chip>);
        expect(screen.getByText('x').className).toMatch(/text-\[12px\]/);
    });
});
