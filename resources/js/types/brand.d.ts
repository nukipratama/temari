/**
 * Hand-written types for the untyped brand generators under resources/brand/.
 * They are plain ESM build scripts and are only ever imported by Vitest (via
 * the `@brand` alias in vitest.config.ts) to pin the shipped token set against
 * the generator that derives it, so they carry no types of their own.
 */

declare module '@brand/grounds.mjs' {
    export function darkGrounds(
        tokens?: Record<string, string>,
    ): Record<string, string>;
    export function contrast(a: string, b: string): number;
    export function luminance(hex: string): number;
}

declare module '@brand/build-tokens.mjs' {
    export const GROUNDS_DARK: Record<string, string>;
    export const DARK_INK: Record<string, string>;
    export const RARITY_INK_DARK: Record<string, string>;
    export const MOOD: Record<string, string>;
    export const MOOD_BG: Record<string, string>;
    export const MOOD_BG_DARK: Record<string, string>;
    export const MOOD_INK_DARK: Record<string, string>;
    export function tintOnDark(
        hex: string,
        ground: string,
        alpha?: number,
    ): string;
    export function inkOnDark(
        hex: string,
        grounds: Record<string, string>,
        target?: number,
    ): string;
}
