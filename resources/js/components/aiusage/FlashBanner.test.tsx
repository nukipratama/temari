import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import FlashBanner from './FlashBanner';

describe('FlashBanner', () => {
    it('renders the flash message', () => {
        render(<FlashBanner message="Mencoba ulang 2 blok untuk Charlie." />);

        expect(
            screen.getByText('Mencoba ulang 2 blok untuk Charlie.'),
        ).toBeInTheDocument();
    });

    it('disappears once dismissed', () => {
        render(<FlashBanner message="Mencoba ulang 2 blok untuk Charlie." />);

        fireEvent.click(screen.getByLabelText('Tutup'));

        expect(
            screen.queryByText('Mencoba ulang 2 blok untuk Charlie.'),
        ).not.toBeInTheDocument();
    });
});
