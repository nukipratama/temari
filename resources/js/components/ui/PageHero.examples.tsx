import Eyebrow from '@/components/ui/Eyebrow';
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
            name: 'composed eyebrow',
            render: () => (
                <PageHero
                    size="quote-lg"
                    italic
                    eyebrow={
                        <div className="mb-3.5 flex items-baseline justify-between gap-3">
                            <Eyebrow token="hero" tone="ink-2">
                                Inbox · 3 unread
                            </Eyebrow>
                            <button
                                type="button"
                                className="focus-ring rounded-xs font-mono text-xs font-semibold text-text-3"
                            >
                                mark all read
                            </button>
                        </div>
                    }
                >
                    what temari had to say
                </PageHero>
            ),
        },
    ],
} satisfies CatalogueEntry;
