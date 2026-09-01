import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import MiniRow from './MiniRow';

describe('MiniRow', () => {
    it('renders the label beside its value', () => {
        render(<MiniRow label="pace" value="5:32/km" />);

        expect(screen.getByText('pace')).toBeInTheDocument();
        expect(screen.getByText('5:32/km')).toBeInTheDocument();
    });

    it('keeps the value on tabular figures so a column of rows lines up', () => {
        render(<MiniRow label="km" value="6.2" />);

        expect(screen.getByText('6.2')).toHaveClass('tabular-nums');
    });
});
