import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import FlashNotice from './FlashNotice';

describe('FlashNotice', () => {
    it('shows the flash and dismisses it', () => {
        render(<FlashNotice message="re-armed 2 block(s)." />);

        expect(screen.getByText('re-armed 2 block(s).')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'close' }));

        expect(
            screen.queryByText('re-armed 2 block(s).'),
        ).not.toBeInTheDocument();
    });
});
