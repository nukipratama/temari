import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import EffortLegend from './EffortLegend';

describe('EffortLegend', () => {
    it('names every effort word, replacing the old mood legend', () => {
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
    });

    it('carries no mood label', () => {
        render(<EffortLegend />);
        expect(screen.queryByText('blazing')).not.toBeInTheDocument();
    });
});
