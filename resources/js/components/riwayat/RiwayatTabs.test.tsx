import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import RiwayatTabs from './RiwayatTabs';

describe('RiwayatTabs', () => {
    it('renders both sub-tab labels linking to their pages', () => {
        render(<RiwayatTabs active="jejak" />);
        expect(screen.getByText('Jejak').closest('a')).toHaveAttribute(
            'href',
            '/activities',
        );
        expect(screen.getByText('Kalender').closest('a')).toHaveAttribute(
            'href',
            '/calendar',
        );
    });

    it('marks the active tab with aria-current', () => {
        render(<RiwayatTabs active="kalender" />);
        expect(screen.getByText('Kalender').closest('a')).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.getByText('Jejak').closest('a')).not.toHaveAttribute(
            'aria-current',
        );
    });
});
