import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import SectionTabs, { type SectionTabItem } from './SectionTabs';

const METRICS = ['scrollWidth', 'clientWidth', 'scrollLeft'] as const;
const savedDescriptors = new Map<string, PropertyDescriptor | undefined>();

function stubMetrics(metrics: Record<(typeof METRICS)[number], number>) {
    for (const prop of METRICS) {
        if (!savedDescriptors.has(prop)) {
            savedDescriptors.set(
                prop,
                Object.getOwnPropertyDescriptor(HTMLElement.prototype, prop),
            );
        }
        Object.defineProperty(HTMLElement.prototype, prop, {
            configurable: true,
            get: () => metrics[prop],
        });
    }
}

function maskClass(): string {
    return (
        screen
            .getByLabelText('Sub-tab')
            .className.split(' ')
            .find((cls) => cls.startsWith('[mask-image:')) ?? ''
    );
}

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
    afterEach(() => {
        for (const [prop, descriptor] of savedDescriptors) {
            if (descriptor) {
                Object.defineProperty(HTMLElement.prototype, prop, descriptor);
            } else {
                delete (HTMLElement.prototype as unknown as Record<string, unknown>)[prop];
            }
        }
        savedDescriptors.clear();
    });

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

    it('shows no fade affordance when the strip fits its container', () => {
        stubMetrics({ scrollWidth: 300, clientWidth: 300, scrollLeft: 0 });
        render(<SectionTabs tabs={TABS} active="today" />);
        expect(maskClass()).toBe('');
    });

    it('fades the trailing edge when tabs overflow and the strip sits at the start', () => {
        stubMetrics({ scrollWidth: 600, clientWidth: 300, scrollLeft: 0 });
        render(<SectionTabs tabs={TABS} active="today" />);
        expect(maskClass()).toContain('transparent_100%)');
        expect(maskClass()).not.toContain('transparent_0');
    });

    it('fades the leading edge once the strip is scrolled to its end', () => {
        stubMetrics({ scrollWidth: 600, clientWidth: 300, scrollLeft: 300 });
        render(<SectionTabs tabs={TABS} active="today" />);
        expect(maskClass()).toContain('transparent_0');
        expect(maskClass()).not.toContain('transparent_100%)');
    });

    it('fades both edges mid-scroll and updates as the strip is scrolled', () => {
        stubMetrics({ scrollWidth: 600, clientWidth: 300, scrollLeft: 0 });
        render(<SectionTabs tabs={TABS} active="today" />);
        expect(maskClass()).not.toContain('transparent_0');

        stubMetrics({ scrollWidth: 600, clientWidth: 300, scrollLeft: 120 });
        fireEvent.scroll(screen.getByLabelText('Sub-tab'));
        expect(maskClass()).toContain('transparent_0');
        expect(maskClass()).toContain('transparent_100%)');
    });
});
