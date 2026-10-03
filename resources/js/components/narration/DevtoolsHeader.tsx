import { type ReactNode } from 'react';

import { Icon, type IconComponent } from '@/components/ui/Icon';

/** The operator screens' title band, with the link back to the devtools index. */
export default function DevtoolsHeader({
    icon,
    title,
    children,
}: Readonly<{ icon?: IconComponent; title: string; children?: ReactNode }>) {
    return (
        <header className="border-b border-border bg-popover">
            <div className="mx-auto flex max-w-page items-center justify-between px-6 py-4 2xl:max-w-page-2xl">
                <div className="flex items-center gap-3">
                    {icon && (
                        <span className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-leaf-deep text-cream">
                            <Icon icon={icon} width={20} aria-hidden />
                        </span>
                    )}
                    <div>
                        <h1 className="font-serif italic text-headline-xs text-foreground">
                            {title}
                        </h1>
                        {children}
                    </div>
                </div>
                <a
                    href="/devtools"
                    className="focus-ring hidden rounded-full px-2 py-1 text-label-micro font-semibold text-text-3 transition hover:text-foreground sm:inline"
                >
                    Temari · Devtools
                </a>
            </div>
        </header>
    );
}
