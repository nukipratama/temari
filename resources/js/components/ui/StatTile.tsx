import { type ReactNode } from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import MetricExplainer from '@/components/MetricExplainer';
import { cn } from '@/lib/cn';
import type { MetricKey } from '@/lib/metricGlossary';

/**
 * Canonical "label + big tabular-nums value (+ optional unit/sub)" tile.
 * Folds together the per-page private copies (HariIni VitalChip/Stat,
 * Runs/Show HeroStat, dashboard Stat). The card surface, value
 * type-scale and text-contrast tier all swap via the `tone` + `size` matrix
 * so each old call site maps to one variant pair without per-call overrides.
 */
const statTileVariants = cva('', {
    variants: {
        tone: {
            // Bare tile, no surface (centered hero numbers).
            plain: '',
            // Bare tile on a sky (dark) panel: on-sky label/unit + cream value.
            plainSky: '',
            // Paper surface with a hairline border.
            card: 'rounded-xl border border-line bg-surface-card',
            cream: 'rounded-xl bg-cream',
            creamDeep: 'rounded-xl bg-cream-deep',
            // Sunken neutral well used inside chart cards.
            sunken: 'rounded-xl bg-line/20',
            // Glass tile on a sky (dark) panel.
            sky: 'rounded-xl border border-cream/[0.12] bg-cream/[0.06]',
        },
        size: {
            sm: '',
            md: '',
            lg: '',
            xl: '',
        },
    },
    compoundVariants: [
        { tone: 'card', size: 'sm', class: 'px-3.5 py-3' },
        { tone: 'card', size: 'md', class: 'px-3.5 py-4' },
        { tone: 'cream', size: 'sm', class: 'px-4 py-3.5' },
        { tone: 'cream', size: 'md', class: 'px-[22px] py-[18px]' },
        { tone: 'creamDeep', size: 'sm', class: 'px-4 py-3.5' },
        { tone: 'creamDeep', size: 'md', class: 'px-[22px] py-[18px]' },
        { tone: 'sky', size: 'sm', class: 'px-4 py-3.5' },
        { tone: 'sky', size: 'md', class: 'px-[22px] py-[18px]' },
        { tone: 'sunken', size: 'sm', class: 'p-2' },
        { tone: 'sunken', size: 'md', class: 'p-2' },
    ],
    defaultVariants: {
        tone: 'cream',
        size: 'md',
    },
});

type StatTileTone = NonNullable<VariantProps<typeof statTileVariants>['tone']>;
type StatTileSize = NonNullable<VariantProps<typeof statTileVariants>['size']>;

interface StatTileProps {
    label: ReactNode;
    value: ReactNode;
    /** Small uppercase unit suffix rendered under the value. */
    unit?: ReactNode;
    /** Supporting line rendered under the value/unit. */
    sub?: ReactNode;
    tone?: StatTileTone;
    size?: StatTileSize;
    /** Center the label/value/sub stack (HariIni run-summary tiles). */
    align?: 'start' | 'center';
    /** Render a leading family-color dot before the label. */
    dotClass?: string;
    /** Inline metric-glossary `(?)` trigger next to the label. */
    explainerKey?: MetricKey;
    /** Override the value text color (family-tinted stats). */
    valueClassName?: string;
    /** Italic display sub line instead of the default mono/sans sub. */
    subVariant?: 'default' | 'quote';
    className?: string;
}

const ON_SKY: Record<StatTileTone, boolean> = {
    plain: false,
    plainSky: true,
    card: false,
    cream: false,
    creamDeep: false,
    sunken: false,
    sky: true,
};

const LABEL_CLASS: Record<StatTileTone, string> = {
    plain: 'text-ink-2',
    plainSky: 'text-ink-on-sky',
    card: 'text-ink-2',
    cream: 'text-ink-2',
    creamDeep: 'text-ink-2',
    sunken: 'text-ink-2',
    sky: 'text-ink-on-sky',
};

/**
 * Value type-scale per size. `sm` is the chart-card compact figure (mono-base
 * via `valueClassName`), `md` the sky-hero stat, `lg` the run-summary stat, and
 * `xl` the dashboard vital number.
 */
const VALUE_SIZE: Record<StatTileSize, string> = {
    sm: 'text-2xl',
    md: 'text-3xl sm:text-4xl',
    lg: 'text-stat',
    xl: 'text-[40px]',
};

export default function StatTile({
    label,
    value,
    unit,
    sub,
    tone = 'cream',
    size = 'md',
    align = 'start',
    dotClass,
    explainerKey,
    valueClassName,
    subVariant = 'default',
    className,
}: Readonly<StatTileProps>) {
    const onSky = ON_SKY[tone];
    const valueColor = valueClassName ?? (onSky ? 'text-cream' : 'text-ink');
    const subColor = onSky ? 'text-ink-on-sky' : 'text-ink-3';

    return (
        <div
            className={cn(
                statTileVariants({ tone, size }),
                align === 'center' && 'text-center',
                className,
            )}
        >
            <div
                className={cn(
                    'mb-1.5 flex items-center gap-1.5 text-label-micro',
                    align === 'center' && 'justify-center',
                    LABEL_CLASS[tone],
                )}
            >
                {dotClass != null && (
                    <span aria-hidden className={cn('h-1.5 w-1.5 rounded-full', dotClass)} />
                )}
                <span>{label}</span>
                {explainerKey != null && <MetricExplainer metricKey={explainerKey} size="xs" />}
            </div>
            <div
                className={cn(
                    'font-sans font-bold leading-none tabular-nums tracking-[-0.02em]',
                    VALUE_SIZE[size],
                    valueColor,
                )}
            >
                {value}
            </div>
            {unit != null && (
                <div className={cn('mt-1 text-label-micro', LABEL_CLASS[tone])}>{unit}</div>
            )}
            {sub != null && (
                <div
                    className={cn(
                        'mt-1',
                        subVariant === 'quote' ? 'font-display text-xs italic' : 'font-sans text-xs',
                        subColor,
                    )}
                >
                    {sub}
                </div>
            )}
        </div>
    );
}
