import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import Design from './Design';

vi.mock('@/components/catalogue/Catalogue', () => ({
    default: () => <p>catalogue</p>,
}));

vi.mock('@/components/catalogue/TokenSheet', () => ({
    default: () => {
        const ground = document.documentElement.dataset.theme ?? 'unset';
        return <p>{`tokens on ${ground}`}</p>;
    },
}));

afterEach(() => {
    delete document.documentElement.dataset.theme;
});

describe('Devtools/Design', () => {
    it('opens on the component catalogue', async () => {
        render(<Design />);

        expect(
            screen.getByRole('heading', { name: 'Design' }),
        ).toBeInTheDocument();
        expect(await screen.findByText('catalogue')).toBeInTheDocument();
        expect(screen.queryByText(/^tokens on/)).not.toBeInTheDocument();
    });

    it('switches to the token sheet and back', async () => {
        render(<Design />);

        fireEvent.click(screen.getByRole('button', { name: 'tokens' }));
        expect(await screen.findByText('tokens on unset')).toBeInTheDocument();
        expect(screen.queryByText('catalogue')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'components' }));
        expect(await screen.findByText('catalogue')).toBeInTheDocument();
    });

    it("re-reads the token sheet when the document's ground changes", async () => {
        document.documentElement.dataset.theme = 'light';
        render(<Design />);
        fireEvent.click(screen.getByRole('button', { name: 'tokens' }));
        expect(await screen.findByText('tokens on light')).toBeInTheDocument();

        act(() => {
            document.documentElement.dataset.theme = 'dark';
        });

        expect(await screen.findByText('tokens on dark')).toBeInTheDocument();
    });
});
