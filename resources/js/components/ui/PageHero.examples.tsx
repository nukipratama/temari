import BackLink from '@/components/ui/BackLink';
import PageHero from '@/components/ui/PageHero';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'PageHero',
    description:
        "A page's top fold: an eyebrow and a serif headline. The size step shapes the page's own hierarchy.",
    usage: `<PageHero eyebrow="Plan · week 6 of 14" size="lg">
    the long one moves to sunday
</PageHero>`,
    states: [
        {
            name: 'default',
            render: () => (
                <PageHero eyebrow="Plan · week 6 of 14">
                    the long one moves to sunday
                </PageHero>
            ),
        },
        {
            name: 'voice register',
            render: () => (
                <PageHero eyebrow="History" size="quote-lg" italic>
                    every run
                    <br />
                    <em className="text-horizon-ink">has a story.</em>
                </PageHero>
            ),
        },
        {
            name: 'with a back link',
            render: () => (
                <PageHero
                    size="md"
                    eyebrow={
                        <BackLink href="#" className="mb-3.5">
                            history · log
                        </BackLink>
                    }
                >
                    tuesday easy, 5.2 km
                </PageHero>
            ),
        },
        {
            name: 'on sky',
            render: () => (
                <div className="rounded-md bg-sky pad-hero">
                    <PageHero eyebrow="Today" size="sm" onSky>
                        an easy one, then rest
                    </PageHero>
                </div>
            ),
        },
    ],
} satisfies CatalogueEntry;
