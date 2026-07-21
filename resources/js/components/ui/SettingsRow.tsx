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
    description?: string;
    /** Inertia route href (e.g., "/pengaturan/zona") */
    href?: string;
    /** External href (e.g., Telegram bot link) */
    externalHref?: string;
    /** Click handler for button-style row */
    onClick?: MouseEventHandler<HTMLButtonElement>;
    /**
     * Trailing control (e.g. a `<Toggle>`) rendered where the chevron would be.
     *
     * A row with its own control is never itself tappable: the whole point is
     * that the control owns the interaction, and a row that both navigates and
     * holds a switch gives two different outcomes for taps a few pixels apart.
     * So this branch always renders a plain container, and `href` /
     * `externalHref` / `onClick` are ignored alongside it.
     */
    control?: ReactNode;
    /**
     * `danger` tints the icon and label ember for destructive rows. Deliberately
     * tint-only rather than a filled red row: a solid red block in a settings
     * list reads as "something is broken" rather than "be careful".
     */
    tone?: 'default' | 'danger';
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
    control,
    tone = 'default',
    children,
}: Readonly<SettingsRowProps>) {
    const isDanger = tone === 'danger';
    const content = (
        <>
            <span className="flex items-center gap-3">
                <Icon
                    icon={icon}
                    width={20}
                    height={20}
                    className={isDanger ? 'text-ember-deep' : 'text-ink-3'}
                    aria-hidden
                />
                <span className="flex flex-col">
                    <span
                        className={cn(
                            'font-sans text-sm font-semibold',
                            isDanger ? 'text-ember-deep' : 'text-ink',
                        )}
                    >
                        {label}
                    </span>
                    {description !== undefined && (
                        <span className="font-sans text-[12px] text-ink-3">{description}</span>
                    )}
                </span>
            </span>
            {control ?? (
                <Icon icon="mdi:chevron-right" width={18} height={18} className="shrink-0 text-ink-3" aria-hidden />
            )}
        </>
    );

    const baseClasses = 'focus-ring -mx-2 flex items-center justify-between gap-3 rounded-xl p-2 text-left transition hover:bg-cream-deep/40';
    // Only the interactive branches get press feedback; the plain-<div> row
    // below is a static readout and must not pretend to be tappable.
    const tappableClasses = cn(baseClasses, 'pressable cursor-pointer');

    if (control) {
        // `children` stays a sibling, not a row child — it is the modal slot the
        // onClick branch below uses, and nesting it inside the flex row would
        // make it a third column.
        return (
            <>
                <div className={baseClasses}>{content}</div>
                {children}
            </>
        );
    }

    if (href) {
        return (
            <Link href={href} className={tappableClasses}>
                {content}
            </Link>
        );
    }

    if (externalHref) {
        return (
            <a href={externalHref} className={tappableClasses}>
                {content}
            </a>
        );
    }

    if (onClick) {
        return (
            <>
                <button type="button" onClick={onClick} className={cn(tappableClasses, 'w-full text-left')}>
                    {content}
                </button>
                {children}
            </>
        );
    }

    return <div className={baseClasses}>{content}</div>;
}
