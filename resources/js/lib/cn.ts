/**
 * Concatenate truthy class names. Lightweight alternative to clsx —
 * good enough for this codebase's needs without adding a dep.
 */
export function cn(...classes: Array<string | false | null | undefined>): string {
    return classes.filter(Boolean).join(' ');
}
