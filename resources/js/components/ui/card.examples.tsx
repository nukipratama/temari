import { Card } from '@/components/ui/card';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'Card',
    description:
        'The bordered surface, for the rare block that needs one: sections are lanes, not cards, and nothing nests inside a card. The empty tone stands in for content that is not there yet.',
    usage: `<Card padding="hero">
    {content}
</Card>`,
    states: [
        {
            name: 'default',
            render: () => (
                <Card>
                    <p className="text-sm text-text-2">
                        4 runs · 31 km, and the long one finally held.
                    </p>
                </Card>
            ),
        },
        {
            name: 'empty tone',
            render: () => (
                <Card tone="empty" padding="hero">
                    <p className="text-sm text-text-2">no plan yet.</p>
                </Card>
            ),
        },
        {
            name: 'paddings',
            render: () => (
                <div className="flex flex-wrap gap-3">
                    {(['panel', 'card', 'hero'] as const).map((padding) => (
                        <Card key={padding} padding={padding}>
                            <span className="text-label-micro text-text-3">
                                {padding}
                            </span>
                        </Card>
                    ))}
                </div>
            ),
        },
        {
            name: 'rendered as a link',
            render: () => (
                <Card
                    render={<a href="#" />}
                    className="focus-ring pressable block"
                >
                    <span className="text-sm text-foreground">
                        open the plan
                    </span>
                </Card>
            ),
        },
    ],
} satisfies CatalogueEntry;
