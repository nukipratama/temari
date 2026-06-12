import { extendTailwindMerge } from 'tailwind-merge';

/**
 * tailwind-merge, taught about the project's typography-tier utilities.
 *
 * `.text-label-small` / `.text-label-micro` / `.text-stat-fluid` (defined in
 * resources/css/app.css) bundle font size (and family/tracking) but no color.
 * Out of the box tailwind-merge misreads their `text-` prefix as a text-*color*
 * and drops them when a real color (`text-ink-2`) is merged in the same call,
 * silently stripping the styling. Registering them in the `font-size` group
 * makes them coexist with a color again.
 */
const twMerge = extendTailwindMerge({
    extend: {
        classGroups: {
            'font-size': ['text-label-small', 'text-label-micro', 'text-stat-fluid'],
        },
    },
});

/**
 * Join truthy class names and resolve conflicting Tailwind utilities so the
 * last one wins. Lets a component ship base utilities that callers override via
 * `className` without depending on fragile CSS source order. Custom theme
 * utilities (e.g. text-ink, mood-*) aren't in tailwind-merge's groups, so they
 * pass through untouched — same as a plain join.
 */
export function cn(...classes: Array<string | false | null | undefined>): string {
    return twMerge(classes.filter(Boolean).join(' '));
}
