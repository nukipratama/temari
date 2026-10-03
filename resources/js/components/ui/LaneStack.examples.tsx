import { Card } from '@/components/ui/card';
import Eyebrow from '@/components/ui/Eyebrow';
import LaneStack from '@/components/ui/LaneStack';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'LaneStack',
    description:
        "A page's column of sections, each in its own lane, split by a dashed hairline. A card lane keeps its own outline and padding.",
    usage: `<LaneStack className="mt-6">
    <ProfileHero … />
    <RaceCard … />
</LaneStack>`,
    states: [
        {
            name: 'sections',
            render: () => (
                <LaneStack>
                    <section>
                        <Eyebrow token="small" tone="ink-3">
                            this week
                        </Eyebrow>
                        <p className="mt-2 text-sm text-foreground">
                            32.3 km across five runs.
                        </p>
                    </section>
                    <section>
                        <Eyebrow token="small" tone="ink-3">
                            last week
                        </Eyebrow>
                        <p className="mt-2 text-sm text-foreground">
                            27.9 km across four runs.
                        </p>
                    </section>
                </LaneStack>
            ),
        },
        {
            name: 'with a card lane',
            render: () => (
                <LaneStack>
                    <section>
                        <Eyebrow token="small" tone="ink-3">
                            time in zone
                        </Eyebrow>
                        <p className="mt-2 text-sm text-foreground">
                            mostly zone 2 this month.
                        </p>
                    </section>
                    <Card className="text-sm font-bold text-foreground">
                        got a race coming up?
                    </Card>
                </LaneStack>
            ),
        },
    ],
} satisfies CatalogueEntry;
