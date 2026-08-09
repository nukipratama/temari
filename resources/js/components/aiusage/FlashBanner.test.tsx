import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import FlashBanner from './FlashBanner';

describe('FlashBanner', () => {
    it('renders the flash message', () => {
        render(<FlashBanner message="Retrying 2 blocks for Charlie." />);

        expect(
            screen.getByText('Retrying 2 blocks for Charlie.'),
        ).toBeInTheDocument();
    });

    it('disappears once dismissed', () => {
        render(<FlashBanner message="Retrying 2 blocks for Charlie." />);

        fireEvent.click(screen.getByLabelText('Close'));

        expect(
            screen.queryByText('Retrying 2 blocks for Charlie.'),
        ).not.toBeInTheDocument();
    });
});
