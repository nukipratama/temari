import ErrorBanner from '@/components/ErrorBanner';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'ErrorBanner',
    description:
        "Surfaces the first message in Inertia's shared error bag as a dismissable alert, so a withErrors() redirect never lands silently.",
    usage: `<ErrorBanner />`,
    states: [
        {
            name: 'with an error',
            sharedProps: {
                errors: {
                    strava: 'Strava said no to the connection, so nothing was synced.',
                },
            },
            render: () => <ErrorBanner />,
        },
        {
            name: 'no errors',
            sharedProps: { errors: {} },
            render: () => <ErrorBanner />,
        },
    ],
} satisfies CatalogueEntry;
