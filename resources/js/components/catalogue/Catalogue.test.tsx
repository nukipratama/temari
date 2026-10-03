import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import type { CatalogueItem } from '@/lib/catalogue';

import Catalogue from './Catalogue';
import { CATALOGUE } from './entries';

function item(group: string, name: string, description = ''): CatalogueItem {
    return {
        id: `${group}-${name}`.toLowerCase(),
        group,
        path: `components/${group}/${name}.tsx`,
        name,
        description,
        usage: `<${name} />`,
        states: [{ name: 'default', render: () => <span>{name} body</span> }],
    };
}

const ITEMS = [
    item('ui', 'Chip', 'A short tinted label.'),
    item('ui', 'PillButton', "The app's button."),
    item('temari', 'Citation', 'Words pointing at their proof.'),
];

afterEach(() => {
    delete document.documentElement.dataset.theme;
});

describe('Catalogue', () => {
    it('defaults to every examples file in components/', () => {
        render(<Catalogue />);

        expect(
            screen.getByText(
                `${CATALOGUE.length} of ${CATALOGUE.length} components`,
            ),
        ).toBeInTheDocument();
    });

    it('lists every entry in the nav by group, linking to its section', () => {
        render(<Catalogue items={ITEMS} />);

        const nav = screen.getByRole('navigation', { name: 'Components' });
        expect(within(nav).getByText('ui')).toBeInTheDocument();
        expect(within(nav).getByText('temari')).toBeInTheDocument();
        expect(within(nav).getByRole('link', { name: 'Chip' })).toHaveAttribute(
            'href',
            '#ui-chip',
        );
        expect(screen.getByRole('region', { name: 'Citation' })).toBeVisible();
        expect(screen.getByText('3 of 3 components')).toBeInTheDocument();
    });

    it('filters the nav and the entries by name, folder or description', () => {
        render(<Catalogue items={ITEMS} />);

        fireEvent.change(screen.getByLabelText('search'), {
            target: { value: 'proof' },
        });

        expect(screen.getByText('1 of 3 components')).toBeInTheDocument();
        expect(screen.getByRole('region', { name: 'Citation' })).toBeVisible();
        expect(
            screen.queryByRole('region', { name: 'Chip' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Chip' }),
        ).not.toBeInTheDocument();
    });

    it('says so when nothing matches', () => {
        render(<Catalogue items={ITEMS} />);

        fireEvent.change(screen.getByLabelText('search'), {
            target: { value: 'carousel ' },
        });

        expect(screen.getByText('nothing matches')).toBeInTheDocument();
        expect(
            screen.getByText(
                'no component name, folder or description contains "carousel".',
            ),
        ).toBeInTheDocument();
    });

    it('pins the document to the light ground while mounted, then restores it', () => {
        document.documentElement.dataset.theme = 'dark';
        const { unmount } = render(<Catalogue items={ITEMS} />);

        expect(document.documentElement.dataset.theme).toBe('light');
        unmount();
        expect(document.documentElement.dataset.theme).toBe('dark');
    });

    it('removes the pin entirely when the document had no ground set', () => {
        const { unmount } = render(<Catalogue items={ITEMS} />);

        unmount();
        expect(document.documentElement).not.toHaveAttribute('data-theme');
    });
});
