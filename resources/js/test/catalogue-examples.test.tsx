import { render } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { CATALOGUE } from '@/components/catalogue/entries';
import EntrySection from '@/components/catalogue/EntrySection';

const examplesFiles = Object.keys(
    import.meta.glob('../components/**/*.examples.tsx'),
);

afterEach(() => {
    vi.restoreAllMocks();
});

describe('design catalogue examples', () => {
    it('discovers one entry per examples file', () => {
        expect(CATALOGUE).toHaveLength(examplesFiles.length);
        expect(new Set(CATALOGUE.map((item) => item.id)).size).toBe(
            CATALOGUE.length,
        );
    });

    it.each(CATALOGUE.map((item) => [item.path, item] as const))(
        '%s renders every state and its matrix on both grounds',
        (_path, item) => {
            const error = vi.spyOn(console, 'error');

            const { container } = render(<EntrySection item={item} />);

            const frames = item.states.length + (item.matrix ? 1 : 0);
            expect(container.querySelectorAll('[data-ground]')).toHaveLength(
                frames * 2,
            );
            expect(error).not.toHaveBeenCalled();
        },
    );
});
