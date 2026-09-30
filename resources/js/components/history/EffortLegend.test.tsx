import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import EffortLegend from './EffortLegend';

describe('EffortLegend', () => {
    it('names every effort word', () => {
        render(<EffortLegend />);
        for (const word of [
            'easy',
            'steady',
            'hard',
            'unscored',
            'planned rest',
        ]) {
            expect(screen.getAllByText(word).length).toBeGreaterThan(0);
        }
    });

    it('names every mood with a dot', () => {
        render(<EffortLegend />);
        expect(screen.getByText('mood')).toBeInTheDocument();
        for (const word of [
            'blazing',
            'easy',
            'wobbly',
            'gassed',
            'overloaded',
            'chill',
        ]) {
            expect(screen.getAllByText(word).length).toBeGreaterThan(0);
        }
    });
});
