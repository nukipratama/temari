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
    it('opens on the component catalogue', () => {
        render(<Design />);

        expect(
            screen.getByRole('heading', { name: 'Design' }),
        ).toBeInTheDocument();
        expect(screen.getByText('catalogue')).toBeInTheDocument();
        expect(screen.queryByText(/^tokens on/)).not.toBeInTheDocument();
    });

    it('switches to the token sheet and back', () => {
        render(<Design />);

        fireEvent.click(screen.getByRole('button', { name: 'tokens' }));
        expect(screen.getByText('tokens on unset')).toBeInTheDocument();
        expect(screen.queryByText('catalogue')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'components' }));
        expect(screen.getByText('catalogue')).toBeInTheDocument();
    });

    it("re-reads the token sheet when the document's ground changes", async () => {
        document.documentElement.dataset.theme = 'light';
        render(<Design />);
        fireEvent.click(screen.getByRole('button', { name: 'tokens' }));
        expect(screen.getByText('tokens on light')).toBeInTheDocument();

        act(() => {
            document.documentElement.dataset.theme = 'dark';
        });

        expect(await screen.findByText('tokens on dark')).toBeInTheDocument();
    });
});
