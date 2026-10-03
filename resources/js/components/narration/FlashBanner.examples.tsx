import FlashBanner from '@/components/narration/FlashBanner';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'FlashBanner',
    description:
        "The operator screens' inline, dismissable confirmation for a flash message, since they render outside the app shell.",
    usage: `{flash.info && <FlashBanner message={flash.info} />}`,
    states: [
        {
            name: 'default',
            render: () => (
                <FlashBanner message="3 failed blocks queued for a retry." />
            ),
        },
    ],
} satisfies CatalogueEntry;
