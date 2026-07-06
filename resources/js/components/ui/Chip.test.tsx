import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Chip, { type ChipTone } from './Chip';

describe('Chip', () => {
    it.each([
        ['neutral', 'bg-ink/[0.06]'],
        ['horizon', 'bg-horizon/[0.18]'],
        ['leaf', 'bg-leaf/[0.18]'],
        ['sky', 'bg-sky/[0.08]'],
        ['onSky', 'bg-cream/10'],
    ] satisfies [ChipTone, string][])('renders tone %s with its background class', (tone, expected) => {
        render(<Chip tone={tone}>label</Chip>);
        expect(screen.getByText('label').className).toContain(expected);
    });

    it('uses md sizing when size="md"', () => {
        render(<Chip size="md">x</Chip>);
        expect(screen.getByText('x').className).toMatch(/text-\[12px\]/);
    });
});
