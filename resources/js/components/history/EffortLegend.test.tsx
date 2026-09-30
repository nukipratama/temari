import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import EffortLegend from './EffortLegend';

describe('EffortLegend', () => {
    it('defaults to the calendar effort key without a mood key', () => {
        render(<EffortLegend />);
        for (const word of [
            'easy',
            'steady',
            'hard',
            'unscored',
            'planned rest',
        ]) {
            expect(screen.getByText(word)).toBeInTheDocument();
        }
        expect(screen.queryByText('mood')).not.toBeInTheDocument();
        expect(screen.queryByText('blazing')).not.toBeInTheDocument();
    });

    it('names every mood with a dot when requested by the feed', () => {
        render(<EffortLegend withMood />);
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
