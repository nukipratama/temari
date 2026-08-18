import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import CollectionTabs from './CollectionTabs';

describe('CollectionTabs', () => {
    it('renders both sub-tab labels', () => {
        render(<CollectionTabs active="cards" />);
        expect(screen.getByText('Cards')).toBeInTheDocument();
        expect(screen.getByText('Accessories')).toBeInTheDocument();
    });

    it('marks only the active tab with aria-current', () => {
        render(<CollectionTabs active="accessories" />);
        expect(screen.getByText('Accessories').closest('a')).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.getByText('Cards').closest('a')).not.toHaveAttribute(
            'aria-current',
        );
    });

    it('shows the count chip only on the active tab when given', () => {
        render(<CollectionTabs active="accessories" activeCount="3" />);
        expect(screen.getByText('3')).toBeInTheDocument();
        expect(screen.getByText('Accessories').closest('a')).toHaveTextContent(
            '3',
        );
        expect(screen.getByText('Cards').closest('a')).not.toHaveTextContent(
            '3',
        );
    });

    it('renders no count chip when activeCount is omitted', () => {
        render(<CollectionTabs active="cards" />);
        expect(
            screen
                .getByText('Cards')
                .closest('a')!
                .querySelector('.bg-horizon\\/25'),
        ).toBeNull();
    });
});
