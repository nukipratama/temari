/**
 * Hand-written types for the untyped source-guard scripts under scripts/.
 * They are plain ESM CLI scripts and are only ever imported by Vitest (via
 * the `@scripts` alias in vitest.config.ts) to test their rule tables
 * directly, so they carry no types of their own.
 */

declare module '@scripts/check-raw-palette.mjs' {
    interface Rule {
        name: string;
        fix: string;
        re: RegExp;
        exemptDir?: string;
    }

    export const RULES: ReadonlyArray<Rule>;
    export function rulesFor(relativePath: string): Rule[];
}
