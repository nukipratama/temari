// PROTOTYPE — throwaway, calendar redesign variants
import { router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useEffect } from 'react';

import { Icon } from '@/components/ui/Icon';

/** The worktree serves a production build, so DEV alone would hide it; local and LAN hosts count too. */
function isPrototypeHost(): boolean {
    if (import.meta.env.DEV) {
        return true;
    }
    const host = globalThis.location?.hostname ?? '';
    return (
        host === 'localhost' ||
        host === '127.0.0.1' ||
        /^(192\.168|10)\./.test(host) ||
        /^172\.(1[6-9]|2\d|3[01])\./.test(host)
    );
}

export default function PrototypeSwitcher<V extends string>({
    variants,
    labels,
    current,
}: Readonly<{
    variants: ReadonlyArray<V>;
    labels: Record<V, string>;
    current: V;
}>) {
    const { url } = usePage();
    const index = variants.indexOf(current);

    const go = (step: number) => {
        const next =
            variants[(index + step + variants.length) % variants.length];
        const [path, query = ''] = url.split('?');
        const params = new URLSearchParams(query);
        params.set('variant', next);
        router.get(
            `${path}?${params.toString()}`,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['month'],
            },
        );
    };

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;
            if (
                target?.closest('input, textarea, select, [contenteditable]') ||
                event.metaKey ||
                event.ctrlKey ||
                event.altKey
            ) {
                return;
            }
            if (event.key === 'ArrowLeft') go(-1);
            if (event.key === 'ArrowRight') go(1);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

    if (!isPrototypeHost()) {
        return null;
    }

    return (
        <div className="pointer-events-none fixed inset-x-0 bottom-[calc(5.75rem+env(safe-area-inset-bottom))] z-40 flex justify-center">
            <div
                role="group"
                aria-label="prototype variant"
                className="pointer-events-auto flex items-center gap-1 rounded-full border-2 border-dashed border-horizon bg-sky p-1 text-cream shadow-e3"
            >
                <button
                    type="button"
                    onClick={() => go(-1)}
                    aria-label="previous variant"
                    className="focus-ring flex size-8 items-center justify-center rounded-full hover:bg-sky-2"
                >
                    <Icon
                        icon={ChevronLeft}
                        width={16}
                        height={16}
                        aria-hidden
                    />
                </button>
                <span className="min-w-32 px-1 text-center font-mono text-xs font-bold whitespace-nowrap">
                    <span className="text-horizon">proto</span>{' '}
                    {labels[current]}
                </span>
                <button
                    type="button"
                    onClick={() => go(1)}
                    aria-label="next variant"
                    className="focus-ring flex size-8 items-center justify-center rounded-full hover:bg-sky-2"
                >
                    <Icon
                        icon={ChevronRight}
                        width={16}
                        height={16}
                        aria-hidden
                    />
                </button>
            </div>
        </div>
    );
}
