/**
 * Hand-written types for the untyped brand generators under resources/brand/.
 * They are plain ESM build scripts and are only ever imported by Vitest (via
 * the `@brand` alias in vitest.config.ts) to pin the shipped components against
 * the delivered artwork, so they carry no types of their own.
 */

declare module '@brand/build-mascot.mjs' {
    export const STATE_NAMES: string[];
    export const SLOT_NAMES: string[];
    export const BOUNDS: {
        top: number;
        bottom: number;
        left: number;
        right: number;
        withAccessories: { bottom: number; outer: number };
    };
    export function mascot(
        state: string,
        options?: {
            size?: number;
            halo?: boolean;
            wearing?: Array<
                string | { slot: string; colour?: string; detail?: string }
            >;
            id?: string;
        },
    ): string;
}

declare module '@brand/build-accessories.mjs' {
    export const ITEMS: Array<{
        key: string;
        slot: string;
        rarity: string;
        name: string;
        colour: string;
        detail: string | null;
        override?: string;
    }>;
}
