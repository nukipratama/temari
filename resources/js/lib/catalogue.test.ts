import { describe, expect, it } from 'vitest';

import {
    axesOf,
    type CatalogueEntry,
    catalogueItems,
    groupItems,
    matchesQuery,
} from './catalogue';

function entry(name: string, description = ''): { default: CatalogueEntry } {
    return {
        default: { name, description, usage: `<${name} />`, states: [] },
    };
}

describe('catalogueItems', () => {
    const items = catalogueItems({
        '../plan/DeltaPair.examples.tsx': entry('DeltaPair'),
        '../TemariMark.examples.tsx': entry('TemariMark'),
        '../ui/PillButton.examples.tsx': entry('PillButton'),
        '../history/RecapCard.examples.tsx': entry('RecapCard'),
        '../temari/Citation.examples.tsx': entry('Citation'),
        '../ui/card.examples.tsx': entry('Card'),
    });

    it('orders ui, temari and shared first, then the feature folders alphabetically', () => {
        expect(items.map((item) => item.name)).toEqual([
            'Card',
            'PillButton',
            'Citation',
            'TemariMark',
            'RecapCard',
            'DeltaPair',
        ]);
    });

    it('derives the group, id and component path from the glob key', () => {
        expect(items[0]).toMatchObject({
            id: 'ui-card',
            group: 'ui',
            path: 'components/ui/card.tsx',
        });
        expect(items[3]).toMatchObject({
            id: 'shared-temarimark',
            group: 'shared',
            path: 'components/TemariMark.tsx',
        });
    });
});

describe('matchesQuery', () => {
    const [item] = catalogueItems({
        '../ui/Chip.examples.tsx': entry('Chip', 'A short tinted label.'),
    });

    it('matches everything on an empty or blank query', () => {
        expect(matchesQuery(item, '')).toBe(true);
        expect(matchesQuery(item, '   ')).toBe(true);
    });

    it('matches the name, group, description or path, ignoring case', () => {
        expect(matchesQuery(item, 'CHIP')).toBe(true);
        expect(matchesQuery(item, 'ui')).toBe(true);
        expect(matchesQuery(item, 'tinted')).toBe(true);
        expect(matchesQuery(item, 'components/ui')).toBe(true);
        expect(matchesQuery(item, 'banner')).toBe(false);
    });
});

describe('groupItems', () => {
    it('buckets items by group, keeping their order', () => {
        const items = catalogueItems({
            '../ui/Chip.examples.tsx': entry('Chip'),
            '../ui/Banner.examples.tsx': entry('Banner'),
            '../temari/Citation.examples.tsx': entry('Citation'),
        });

        expect(
            groupItems(items).map(([group, members]) => [
                group,
                members.map((member) => member.name),
            ]),
        ).toEqual([
            ['ui', ['Banner', 'Chip']],
            ['temari', ['Citation']],
        ]);
    });
});

describe('axesOf', () => {
    it('lists the value names of the chosen axes only', () => {
        expect(
            axesOf(
                {
                    tone: { neutral: 'a', warning: 'b' },
                    size: { sm: 'c' },
                    onSky: { true: '', false: '' },
                },
                ['tone', 'size'],
            ),
        ).toEqual({ tone: ['neutral', 'warning'], size: ['sm'] });
    });
});
