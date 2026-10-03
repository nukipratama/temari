import TemariMark from '@/components/TemariMark';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'TemariMark',
    description:
        'The brand mark: two nested open arcs, the outer one on horizon. The inner arc follows the foreground unless a colour is passed.',
    usage: `<TemariMark size={22} />`,
    states: [
        {
            name: 'sizes',
            render: () => (
                <div className="flex items-end gap-4">
                    {[16, 22, 32, 48].map((size) => (
                        <TemariMark key={size} size={size} />
                    ))}
                </div>
            ),
        },
        {
            name: 'on sky',
            render: () => (
                <div className="inline-flex rounded-md bg-sky pad-card">
                    <TemariMark size={32} color="var(--color-cream)" />
                </div>
            ),
        },
    ],
} satisfies CatalogueEntry;
