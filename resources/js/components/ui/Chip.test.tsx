import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import Chip, { type ChipTone } from './Chip';

describe('Chip', () => {
    it.each([
        ['neutral', 'bg-muted'],
        ['horizon', 'bg-horizon/[0.18]'],
        ['positive', 'bg-leaf/[0.18]'],
        ['warning', 'bg-ember/[0.18]'],
    ] satisfies [ChipTone, string][])(
        'renders tone %s with its background class',
        (tone, expected) => {
            render(<Chip tone={tone}>label</Chip>);
            expect(screen.getByText('label').className).toContain(expected);
        },
    );

    it('uses md sizing when size="md"', () => {
        render(<Chip size="md">x</Chip>);
        expect(screen.getByText('x').className).toMatch(/\btext-xs\b/);
    });
});
