import { Icon } from '@iconify/react';
import { Head } from '@inertiajs/react';

interface DevtoolsLink {
    icon: string;
    label: string;
    desc: string;
    href: string;
}

const LINKS: ReadonlyArray<DevtoolsLink> = [
    {
        icon: 'mdi:currency-usd',
        label: 'AI Usage',
        desc: 'Token spend, budget gauge, and self-heal panel.',
        href: '/ai-usage',
    },
    {
        icon: 'mdi:sail-boat',
        label: 'Horizon',
        desc: 'Queue worker & job monitoring.',
        href: '/horizon',
    },
    {
        icon: 'mdi:pulse',
        label: 'Pulse',
        desc: 'Server, request, and exception metrics.',
        href: '/pulse',
    },
];

export default function Devtools() {
    return (
        <>
            <Head title="Devtools · Temari" />
            <div className="flex min-h-screen flex-col items-center gap-8 bg-cream-deep px-8 py-16 text-ink">
                <h1 className="font-display italic text-display-xs text-ink">
                    Devtools
                </h1>
                <ul className="grid w-full max-w-[560px] gap-3.5">
                    {LINKS.map((link) => (
                        <li key={link.href}>
                            <a
                                href={link.href}
                                className="flex items-center gap-4 rounded-2xl border border-cream-deep bg-cream px-5 py-4 transition hover:border-horizon/40"
                            >
                                <span
                                    aria-hidden
                                    className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-horizon/[0.18] text-horizon-deep"
                                >
                                    <Icon
                                        icon={link.icon}
                                        width={20}
                                        height={20}
                                        aria-hidden
                                    />
                                </span>
                                <div>
                                    <div className="font-sans text-sm font-semibold text-ink">
                                        {link.label}
                                    </div>
                                    <div className="mt-1 font-sans text-xs leading-snug text-ink-3">
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
