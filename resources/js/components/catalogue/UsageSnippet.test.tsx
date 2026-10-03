import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import UsageSnippet from './UsageSnippet';

describe('UsageSnippet', () => {
    it('shows the snippet and copies it to the clipboard', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        vi.stubGlobal('navigator', { clipboard: { writeText } });

        render(<UsageSnippet code={'<Chip tone="positive">holding</Chip>'} />);

        expect(
            screen.getByText('<Chip tone="positive">holding</Chip>'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'copy' }));

        expect(writeText).toHaveBeenCalledWith(
            '<Chip tone="positive">holding</Chip>',
        );
        expect(
            await screen.findByRole('button', { name: 'copied' }),
        ).toBeInTheDocument();
    });
});
