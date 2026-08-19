import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import MeTabs from './MeTabs';

describe('MeTabs', () => {
    it('renders all three segment labels linking to their pages', () => {
        render(<MeTabs active="profile" />);
        expect(screen.getByText('Profile').closest('a')).toHaveAttribute(
            'href',
            '/profile',
        );
        expect(screen.getByText('Settings').closest('a')).toHaveAttribute(
            'href',
            '/settings',
        );
        expect(screen.getByText('Accessories').closest('a')).toHaveAttribute(
            'href',
            '/accessories',
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
        expect(
            screen.getByText('Accessories').closest('a'),
        ).not.toHaveAttribute('aria-current');
    });
});
