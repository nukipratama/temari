import { Flame, HeartPulse, Moon } from 'lucide-react';

import { Icon, StravaIcon, TelegramIcon } from '@/components/ui/Icon';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'Icon',
    description:
        'Sizes a lucide glyph in rem so it scales with the type step. StravaIcon and TelegramIcon are the vendor marks, never redrawn.',
    usage: `<Icon icon={HeartPulse} width={16} height={16} aria-hidden />`,
    states: [
        {
            name: 'sizes',
            render: () => (
                <div className="flex items-end gap-4 text-foreground">
                    {[12, 16, 20, 24].map((size) => (
                        <Icon
                            key={size}
                            icon={HeartPulse}
                            width={size}
                            height={size}
                            aria-hidden
                        />
                    ))}
                </div>
            ),
        },
        {
            name: 'tinted',
            render: () => (
                <div className="flex gap-4">
                    <Icon icon={Flame} className="text-ember-ink" aria-hidden />
                    <Icon icon={Moon} className="text-text-3" aria-hidden />
                    <Icon
                        icon={HeartPulse}
                        className="text-icon-accent"
                        aria-hidden
                    />
                </div>
            ),
        },
        {
            name: 'brand marks',
            render: () => (
                <div className="flex gap-4 text-foreground">
                    <Icon
                        icon={StravaIcon}
                        width={20}
                        height={20}
                        aria-hidden
                    />
                    <Icon
                        icon={TelegramIcon}
                        width={20}
                        height={20}
                        aria-hidden
                    />
                </div>
            ),
        },
    ],
} satisfies CatalogueEntry;
