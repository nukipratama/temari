import MascotWatermark from '@/components/temari/MascotWatermark';
import { Card } from '@/components/ui/card';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'MascotWatermark',
    description:
        "Temari as a faint watermark bleeding off a card's open corner, behind the content. The card must be relative, isolate and overflow-hidden.",
    usage: `<div className="relative isolate overflow-hidden …">
    {content}
    <MascotWatermark pose="blazing" className="-right-14 -bottom-15" />
</div>`,
    states: [
        {
            name: 'in a card',
            render: () => (
                <Card
                    padding="hero"
                    className="relative isolate max-w-[340px] overflow-hidden"
                >
                    <div className="font-serif text-headline-sm italic">
                        week 38
                    </div>
                    <p className="mt-1 text-sm text-text-2">
                        4 runs · 31 km, and the long one finally held.
                    </p>
                    <MascotWatermark
                        pose="blazing"
                        className="-right-14 -bottom-15"
                    />
                </Card>
            ),
        },
    ],
} satisfies CatalogueEntry;
