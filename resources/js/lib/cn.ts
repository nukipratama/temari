import { extendTailwindMerge } from 'tailwind-merge';

/**
 * tailwind-merge, taught about the project's typography-tier utilities.
 *
 * `.text-label-small` / `.text-label-micro` / `.text-label-hero` (defined in
 * resources/css/app.css) and the `--text-display-*` / `--text-headline-*` /
 * `--text-quote-*` / `--text-stat` / `--text-stat-fluid` / `--text-stat-fluid-lg`
 * scale tokens (the `@theme` block) bundle font size (and sometimes family/tracking) but no
 * color. Out of the box tailwind-merge misreads their `text-` prefix as a
 * text-*color* and drops them when a real color (`text-text-2`) is merged in
 * the same call, silently stripping the styling — confirmed live in
 * StatTile's `lg` size (`text-stat` + a color) before this fix. Registering
 * them in the `font-size` group makes them coexist with a color again.
 */
const twMerge = extendTailwindMerge({
    extend: {
        classGroups: {
            'font-size': [
                'text-label-small',
                'text-label-micro',
                'text-label-hero',
                'text-stat',
                'text-stat-fluid',
                'text-stat-fluid-lg',
                'text-display-2xl',
                'text-display-xl',
                'text-display-lg',
                'text-display-md',
                'text-display-sm',
                'text-display-xs',
                'text-headline-lg',
                'text-headline-md',
                'text-headline-sm',
                'text-headline-xs',
                'text-quote-lg',
                'text-quote-md',
                'text-quote-sm',
            ],
        },
    },
});

/**
 * Join truthy class names and resolve conflicting Tailwind utilities so the
 * last one wins. Lets a component ship base utilities that callers override via
 * `className` without depending on fragile CSS source order. Custom theme
 * utilities (e.g. text-foreground, mood-*) aren't in tailwind-merge's groups, so they
 * pass through untouched — same as a plain join.
 */
export function cn(
    ...classes: Array<string | false | null | undefined>
): string {
    return twMerge(classes.filter(Boolean).join(' '));
}
