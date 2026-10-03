import FlashNotice from '@/components/FlashNotice';
import { type CatalogueEntry } from '@/lib/catalogue';

const NONE = { success: null, error: null, info: null };

export default {
    name: 'FlashNotice',
    description:
        'Surfaces the flash.error, flash.info or flash.success shared prop as a dismissable banner, error first.',
    usage: `<FlashNotice />`,
    states: [
        {
            name: 'success',
            sharedProps: {
                flash: {
                    ...NONE,
                    success: 'race saved. the plan is rebuilding.',
                },
            },
            render: () => <FlashNotice />,
        },
        {
            name: 'info',
            sharedProps: {
                flash: {
                    ...NONE,
                    info: 'the pull from Strava is paused, so nothing was synced.',
                },
            },
            render: () => <FlashNotice />,
        },
        {
            name: 'error',
            sharedProps: {
                flash: { ...NONE, error: "that didn't save. try again." },
            },
            render: () => <FlashNotice />,
        },
        {
            name: 'nothing flashed',
            sharedProps: { flash: NONE },
            render: () => <FlashNotice />,
        },
    ],
} satisfies CatalogueEntry;
