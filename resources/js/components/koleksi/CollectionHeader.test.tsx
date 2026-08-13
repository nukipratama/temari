import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import CollectionHeader from './CollectionHeader';

describe('CollectionHeader', () => {
    it('renders the eyebrow and two-line headline', () => {
        render(
            <CollectionHeader
                active="cards"
                eyebrow="Koleksi"
                headline1="Kartu-kartu"
                headline2="larimu"
            />,
        );
        expect(screen.getByText('Koleksi')).toBeInTheDocument();
        expect(
            screen.getByText('Kartu-kartu,', { exact: false }),
        ).toBeInTheDocument();
        expect(screen.getByText('larimu')).toBeInTheDocument();
    });

    it('renders the sub-tabs with the given tab marked active', () => {
        render(
            <CollectionHeader
                active="records"
                eyebrow="Koleksi"
                headline1="Your best"
                headline2="times"
            />,
        );
        expect(screen.getByText('Records').closest('a')).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.getByText('Cards').closest('a')).not.toHaveAttribute(
            'aria-current',
        );
    });

    it('forwards the activeCount chip to the active sub-tab', () => {
        render(
            <CollectionHeader
                active="cards"
                eyebrow="Koleksi"
                headline1="Your"
                headline2="cards"
                activeCount="12"
            />,
        );
        expect(screen.getByText('12')).toBeInTheDocument();
    });
});
