/*
 * Derives the set of grounds an `-ink` token has to survive.
 *
 * S2.9 scored the ink tier against a hand-written list of five grounds. The
 * list omitted the one AppShell actually paints, so every hue-derived ink
 * shipped at ~4.3:1 while three audits reported a pass. Nothing here is a list
 * of grounds: the values come out of the stylesheet, the set of backgrounds in
 * play comes out of the components, and grounds.json only says what *kind* each
 * one is. A background in use with no entry in grounds.json is an error, not a
 * skip.
 */

import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.dirname(path.dirname(here));

export const APP_CSS = path.join(root, 'resources/css/app.css');
export const COMPONENT_DIR = path.join(root, 'resources/js');

export const KINDS = JSON.parse(
  readFileSync(path.join(here, 'grounds.json'), 'utf8'),
);

/** Every `--color-*` declared in the `@theme static` block. */
export function readColorTokens(css = readFileSync(APP_CSS, 'utf8')) {
  const theme = css.match(/@theme static \{[\s\S]*?\n\}/);
  if (!theme) {
    throw new Error('app.css has no `@theme static` block to read tokens from.');
  }

  const tokens = {};
  for (const [, name, value] of theme[0].matchAll(
    /--color-([a-z0-9-]+):\s*(#[0-9a-f]{6});/g,
  )) {
    tokens[name] = value;
  }
  return tokens;
}

/** Each `--color-surface` override dawn-shift declares, keyed by its bucket. */
export function readDawnShiftSurfaces(css = readFileSync(APP_CSS, 'utf8')) {
  const shifts = {};
  for (const [, name, value] of css.matchAll(
    /body\[data-time-of-day='([a-z]+)'\]\s*\{\s*--color-surface:\s*(#[0-9a-f]{6});/g,
  )) {
    shifts[name] = value;
  }
  return shifts;
}

const SOURCE_SUFFIXES = ['.ts', '.tsx'];

function sourceFiles(dir) {
  return readdirSync(dir, { recursive: true, withFileTypes: true })
    .filter(
      (entry) =>
        entry.isFile() &&
        SOURCE_SUFFIXES.some((suffix) => entry.name.endsWith(suffix)),
    )
    .map((entry) => path.join(entry.parentPath, entry.name));
}

/* Comments carry prose like `bg-mood-{key}`, which is not a class the browser
   ever sees; scanning them would invent backgrounds that do not exist. */
function stripComments(source) {
  return source.replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/\/\/[^\n]*/g, ' ');
}

/**
 * Every `bg-*` utility the components paint, with any `/alpha` modifier
 * dropped. Alpha composites against an ancestor this scan cannot resolve, so
 * the underlying colour is what gets classified and scored.
 */
export function enumerateBackgrounds(dir = COMPONENT_DIR) {
  const names = new Set();
  for (const file of sourceFiles(dir)) {
    const source = stripComments(readFileSync(file, 'utf8'));
    for (const [, name] of source.matchAll(
      /\bbg-([a-z][a-z0-9]*(?:-[a-z0-9]+)*)\b/g,
    )) {
      names.add(name);
    }
  }
  return [...names].sort();
}

/** Backgrounds the components paint that grounds.json does not classify. */
export function unclassifiedBackgrounds(dir = COMPONENT_DIR) {
  const known = new Set([
    ...KINDS.paper,
    ...KINDS.scoped,
    ...KINDS.fill,
    ...KINDS.keyword,
  ]);
  return enumerateBackgrounds(dir).filter((name) => !known.has(name));
}

/**
 * The papers any ink can land on: every background classified `paper`, plus
 * each surface dawn-shift drifts to. Throws when a background in use is
 * unclassified, and when a classified one has no token behind it.
 */
export function paperGrounds(tokens = readColorTokens(), dir = COMPONENT_DIR) {
  const unclassified = unclassifiedBackgrounds(dir);
  if (unclassified.length > 0) {
    throw new Error(
      `Unclassified background${unclassified.length > 1 ? 's' : ''} in use: ` +
        `${unclassified.join(', ')}. Add each to resources/brand/grounds.json ` +
        `as paper (ink lands on it), scoped (only its own family's ink does), ` +
        `fill (no ink text) or keyword (not a --color-* token).`,
    );
  }

  const grounds = {};
  for (const name of KINDS.paper) {
    if (tokens[name] === undefined) {
      throw new Error(`grounds.json classifies "${name}" as paper, but --color-${name} is not declared.`);
    }
    grounds[name] = tokens[name];
  }
  for (const [bucket, value] of Object.entries(readDawnShiftSurfaces())) {
    grounds[`surface · ${bucket}`] = value;
  }
  return grounds;
}

/**
 * Every ground `--color-<family>-ink` has to clear: the papers, plus the
 * family's own `-bg` cell when it paints one. The pairing is the naming
 * convention, so a new tinted cell is scored the moment it is classified.
 */
export function groundsForInk(family, tokens, papers) {
  const own = `${family}-bg`;
  return KINDS.scoped.includes(own) && tokens[own] !== undefined
    ? { ...papers, [own]: tokens[own] }
    : { ...papers };
}

/** The ground a token is hardest to read on. */
export function darkest(grounds) {
  return Object.values(grounds).reduce((a, b) => (luminance(a) <= luminance(b) ? a : b));
}

export function luminance(hex) {
  const n = parseInt(hex.slice(1), 16);
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255]
    .map((v) => {
      const c = v / 255;
      return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    })
    .reduce((sum, c, i) => sum + [0.2126, 0.7152, 0.0722][i] * c, 0);
}

export function contrast(a, b) {
  const [x, y] = [luminance(a), luminance(b)].sort((p, q) => q - p);
  return (x + 0.05) / (y + 0.05);
}
