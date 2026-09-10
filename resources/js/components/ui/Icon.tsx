import type { ComponentType, SVGProps } from 'react';

export type IconComponent = ComponentType<SVGProps<SVGSVGElement>>;

/**
 * Brand marks. Never restyled or re-drawn in lucide's stroke style — Strava
 * can revoke API access for brand-guideline violations, and Telegram's glyph
 * is a fixed, recognisable mark.
 */
export function StravaIcon(props: SVGProps<SVGSVGElement>) {
    return (
        <svg viewBox="0 0 24 24" {...props}>
            <path
                fill="currentColor"
                d="M14.92 17.16l1.83-3.63h2.7l-4.51 8.97l-4.57-8.97h2.7l1.85 3.63m-4.29-8.5l-2.45 4.89H4.55L10.61 1.5l6.13 12.05h-3.63l-2.48-4.89z"
            />
        </svg>
    );
}

export function TelegramIcon(props: SVGProps<SVGSVGElement>) {
    return (
        <svg viewBox="0 0 24 24" {...props}>
            <path
                fill="currentColor"
                d="M9.78 18.65l.28-4.23l7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3L3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"
            />
        </svg>
    );
}

export function Icon({
    icon: Glyph,
    width,
    height,
    className,
    ...rest
}: { icon: IconComponent } & Omit<SVGProps<SVGSVGElement>, 'icon'>) {
    const size = width ?? height ?? 24;

    return <Glyph width={size} height={size} className={className} {...rest} />;
}
