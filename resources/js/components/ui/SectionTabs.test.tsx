import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import SectionTabs, { type SectionTabItem } from './SectionTabs';

type TabId = 'today' | 'history';

const TABS: ReadonlyArray<SectionTabItem<TabId>> = [
    { id: 'today', label: 'Today', href: '/', icon: 'mdi:weather-sunset-up' },
    {
        id: 'history',
        label: 'History',
        href: '/activities',
        icon: 'mdi:history',
    },
];

describe('SectionTabs', () => {
    it('renders every tab label linking to its target path', () => {
        render(<SectionTabs tabs={TABS} active="today" />);
        expect(screen.getByText('Today').closest('a')).toHaveAttribute(
            'href',
            '/',
        );
        expect(screen.getByText('History').closest('a')).toHaveAttribute(
            'href',
            '/activities',
        );
    });

    it('marks only the active tab with aria-current', () => {
        render(<SectionTabs tabs={TABS} active="history" />);
        expect(screen.getByText('History').closest('a')).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.getByText('Today').closest('a')).not.toHaveAttribute(
            'aria-current',
        );
    });

    it('shows the count chip only on the active tab when given', () => {
        render(<SectionTabs tabs={TABS} active="history" activeCount="12" />);
        expect(screen.getByText('12')).toBeInTheDocument();
        expect(screen.getByText('History').closest('a')).toHaveTextContent(
            '12',
        );
        expect(screen.getByText('Today').closest('a')).not.toHaveTextContent(
            '12',
        );
    });

    it('renders no count chip when activeCount is omitted', () => {
        render(<SectionTabs tabs={TABS} active="today" />);
        expect(
            screen
                .getByText('Today')
                .closest('a')!
                .querySelector('.bg-horizon\\/25'),
        ).toBeNull();
    });
});
