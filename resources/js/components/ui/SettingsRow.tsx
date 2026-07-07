import { Link } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { type MouseEventHandler, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface SettingsRowProps {
    /** Iconify icon name (e.g., "mdi:heart-pulse") */
    icon: string;
    /** Main label (e.g., "Zona HR") */
    label: string;
    /** Secondary description (e.g., "Atur sendiri batas Z1-Z5...") */
    description: string;
    /** Inertia route href (e.g., "/pengaturan/zona") */
    href?: string;
    /** External href (e.g., Telegram bot link) */
    externalHref?: string;
    /** Click handler for button-style row */
    onClick?: MouseEventHandler<HTMLButtonElement>;
    /** Extra children rendered after the row (e.g., modals) */
    children?: ReactNode;
}

export default function SettingsRow({
    icon,
    label,
    description,
    href,
    externalHref,
    onClick,
    children,
}: Readonly<SettingsRowProps>) {
    const content = (
        <>
            <span className="flex items-center gap-3">
                <Icon icon={icon} width={20} height={20} className="text-ink-3" aria-hidden />
                <span className="flex flex-col">
                    <span className="font-sans text-sm font-semibold text-ink">{label}</span>
                    <span className="font-sans text-[12px] text-ink-3">{description}</span>
                </span>
            </span>
            <Icon icon="mdi:chevron-right" width={18} height={18} className="shrink-0 text-ink-3" aria-hidden />
        </>
    );

    const baseClasses = 'focus-ring -mx-2 flex items-center justify-between gap-3 rounded-xl p-2 text-left transition hover:bg-cream-deep/40';

    if (href) {
        return (
            <Link href={href} className={cn(baseClasses, 'cursor-pointer')}>
                {content}
            </Link>
        );
    }

    if (externalHref) {
        return (
            <a href={externalHref} className={cn(baseClasses, 'cursor-pointer')}>
                {content}
            </a>
        );
    }

    if (onClick) {
        return (
            <>
                <button type="button" onClick={onClick} className={cn(baseClasses, 'w-full text-left')}>
                    {content}
                </button>
                {children}
            </>
        );
    }

    return <div className={baseClasses}>{content}</div>;
}
