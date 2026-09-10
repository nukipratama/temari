import { Head } from '@inertiajs/react';
import { Activity, DollarSign, Flag, Palette, Sailboat } from 'lucide-react';

import { Icon, IconComponent } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { cardVariants } from '@/lib/variants';

interface DevtoolsLink {
    icon: IconComponent;
    label: string;
    desc: string;
    href: string;
}

const LINKS: ReadonlyArray<DevtoolsLink> = [
    {
        icon: Palette,
        label: 'Design',
        desc: 'The token set, type specimens, and the contrast audit read live.',
        href: '/devtools/design',
    },
    {
        icon: DollarSign,
        label: 'Narration',
        desc: 'Spend per athlete, the ceilings, and who was served by what.',
        href: '/devtools/narration',
    },
    {
        icon: Flag,
        label: 'Feedback',
        desc: 'The flags runners have filed as wrong, newest first.',
        href: '/devtools/feedback',
    },
    {
        icon: Sailboat,
        label: 'Horizon',
        desc: 'Queue worker & job monitoring.',
        href: '/devtools/horizon',
    },
    {
        icon: Activity,
        label: 'Pulse',
        desc: 'Server, request, and exception metrics.',
        href: '/devtools/pulse',
    },
];

export default function Devtools() {
    return (
        <>
            <Head title="Devtools · Temari" />
            <div className="flex min-h-screen flex-col items-center gap-8 bg-background px-8 py-16 text-foreground">
                <div className="text-center">
                    <h1 className="font-serif italic text-headline-xs text-foreground">
                        Devtools
                    </h1>
                    <p className="mt-2 text-sm text-text-2">
                        Internal tools. Gated behind HTTP Basic in production.
                    </p>
                </div>
                <ul className="grid w-full max-w-[560px] gap-3.5">
                    {LINKS.map((link) => (
                        <li key={link.href}>
                            <a
                                href={link.href}
                                className={cn(
                                    cardVariants({
                                        tone: 'card',
                                        padding: 'card',
                                    }),
                                    'focus-ring flex items-center gap-4 transition hover:border-horizon/40',
                                )}
                            >
                                <span
                                    aria-hidden
                                    className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-horizon/[0.18] text-horizon-ink"
                                >
                                    <Icon
                                        icon={link.icon}
                                        width={20}
                                        height={20}
                                        aria-hidden
                                    />
                                </span>
                                <div>
                                    <div className="font-sans text-sm font-semibold text-foreground">
                                        {link.label}
                                    </div>
                                    <div className="mt-1 font-sans text-xs leading-snug text-text-3">
                                        {link.desc}
                                    </div>
                                </div>
                            </a>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}
