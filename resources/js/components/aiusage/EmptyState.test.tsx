import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import EmptyState from './EmptyState';

describe('EmptyState', () => {
    it('names the empty window rather than the whole dataset', () => {
        render(<EmptyState />);

        expect(
            screen.getByText('Belum ada catatan token di rentang ini.'),
        ).toBeInTheDocument();
    });
});
