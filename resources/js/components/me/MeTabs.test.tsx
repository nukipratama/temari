import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import MeTabs from './MeTabs';

describe('MeTabs', () => {
    it('renders both segment labels linking to their pages', () => {
        render(<MeTabs active="profile" />);
        expect(screen.getByText('Profile').closest('a')).toHaveAttribute(
            'href',
            '/profile',
        );
        expect(screen.getByText('Settings').closest('a')).toHaveAttribute(
            'href',
            '/settings',
        );
    });

    it('marks the active segment with aria-current', () => {
        render(<MeTabs active="settings" />);
        expect(screen.getByText('Settings').closest('a')).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.getByText('Profile').closest('a')).not.toHaveAttribute(
            'aria-current',
        );
    });

    // Accessories is cut (mobile-UX port ledger); the tab must never come back
    // as a dangling nav entry to a retired equip-locker.
    it('does not render an Accessories tab', () => {
        render(<MeTabs active="profile" />);
        expect(screen.queryByText('Accessories')).not.toBeInTheDocument();
    });
});
