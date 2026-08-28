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
 * Panel call sites grounds.json does not register, and registered ones nothing
 * paints any more. The mount is recorded per file rather than per panel class
 * because that is the part a scan cannot see: the same `bg-sky/40` is a tile on
 * a hero panel in one file and a tile on the page ground in another, and only
 * the second one is a contrast bug. A new file painting a registered panel is
 * therefore a new claim to check, not a silent reuse.
 */
export function panelSiteDrift(dir = COMPONENT_DIR, tokens = readColorTokens()) {
  const painted = enumerateAlphaPanels(dir, tokens);
  const unregistered = [];
  const stale = [];

  for (const [spec, files] of Object.entries(painted)) {
    const entry = KINDS.panel[spec];
    if (entry === undefined) {
      unregistered.push(spec);
      continue;
    }
    for (const file of files) {
      if (entry.over?.[file] === undefined && entry.text.length > 0) {
        unregistered.push(`${spec} @ ${file}`);
      }
    }
  }

  for (const [spec, entry] of Object.entries(KINDS.panel)) {
    if (painted[spec] === undefined) {
      stale.push(spec);
      continue;
    }
    for (const file of Object.keys(entry.over ?? {})) {
      if (!painted[spec].includes(file)) stale.push(`${spec} @ ${file}`);
    }
  }

  return { unregistered, stale };
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
 * The three surfaces the dark ground actually uses: sky-deep (background),
 * sky (card), sky-2 (popover/secondary/muted/accent). Fixed by the token
 * model rather than scanned like paperGrounds() — nothing paints a dark
 * surface via a literal bg-<name> class the way paper grounds are classified,
 * since the same `bg-card`/`bg-background` utilities repaint per
 * `[data-theme]` at runtime instead of naming a different token per ground.
 */
export function darkGrounds(tokens = readColorTokens()) {
  const names = ['sky-deep', 'sky', 'sky-2'];
  const grounds = {};
  for (const name of names) {
    if (tokens[name] === undefined) {
      throw new Error(`darkGrounds expects --color-${name} to be declared in @theme static.`);
    }
    grounds[name] = tokens[name];
  }
  return grounds;
}

const toRgb = (hex) => {
  const n = parseInt(hex.slice(1), 16);
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
};

/** `fill` at `alpha` over `ground`, the way the compositor does it. */
export function composite(fill, alpha, ground) {
  const [f, g] = [toRgb(fill), toRgb(ground)];
  return (
    '#' +
    f
      .map((v, i) => Math.round(v * alpha + g[i] * (1 - alpha)))
      .map((v) => v.toString(16).padStart(2, '0'))
      .join('')
  );
}

/** Every `bg-<name>/<alpha>` a component paints under `text-<name>-ink`. */
export function enumerateInkTints(dir = COMPONENT_DIR) {
  const alphas = /\bbg-([a-z][a-z0-9]*(?:-[a-z0-9]+)*)\/(?:\[([0-9.]+)\]|([0-9]{1,3}))/g;
  const heaviest = {};

  for (const file of sourceFiles(dir)) {
    const source = stripComments(readFileSync(file, 'utf8'));
    for (const [literal] of source.matchAll(/'[^']*'|"[^"]*"|`[^`]*`/g)) {
      for (const [, name, bracket, plain] of literal.matchAll(alphas)) {
        if (!new RegExp(`text-${name}-ink\\b`).test(literal)) {
          continue;
        }
        const alpha =
          bracket === undefined ? Number(plain) / 100 : Number(bracket);
        heaviest[name] = Math.max(heaviest[name] ?? 0, alpha);
      }
    }
  }
  return heaviest;
}

const PANEL =
  /(?:^|[\s'"`])((?:[a-z0-9-]+:)*)bg-([a-z][a-z0-9]*(?:-[a-z0-9]+)*)\/(?:\[([0-9.]+)\]|([0-9]{1,3}))(?![\w\-.])/g;
const TEXT =
  /(?:^|[\s'"`])((?:[a-z0-9-]+:)*)text-([a-z][a-z0-9]*(?:-[a-z0-9]+)*)(?:\/(?:\[([0-9.]+)\]|([0-9]{1,3})))?(?![\w-])/g;

const alphaOf = (bracket, plain) =>
  bracket === undefined ? Number(plain) / 100 : Number(bracket);

/**
 * String literals, the way the language delimits them.
 *
 * A quoted literal cannot span a raw newline, but a naive `'[^']*'` does: an
 * apostrophe in JSX text ("Temari's") opens a match that runs to the next one,
 * swallowing whole subtrees and pairing a background in one element with text
 * in another. Bounding the quoted forms to a single line kills that, and the
 * bracket check drops what is left of a mis-paired line, since no Tailwind
 * class contains an angle or curly bracket.
 */
const LITERAL = /'[^'\n]*'|"[^"\n]*"|`[^`]*`/g;

function isClassString(literal) {
  return !/[<>{}]/.test(literal);
}

/**
 * Every `bg-<token>/<alpha>` the components paint, as `token/alpha`.
 *
 * `enumerateBackgrounds` drops the alpha on purpose, so a translucent panel is
 * classified as the solid token it is a tint of. That is the right call for
 * *classifying* it and the wrong one for *scoring* it: `bg-sky/40` is not sky,
 * it is whatever sky at 40% lands on, and the text an author picks for it is
 * the text sky would take.
 */
export function enumerateAlphaPanels(dir = COMPONENT_DIR, tokens = readColorTokens()) {
  const sites = {};
  for (const file of sourceFiles(dir)) {
    const rel = path.relative(path.dirname(path.dirname(dir)), file);
    for (const [, , name, bracket, plain] of stripComments(
      readFileSync(file, 'utf8'),
    ).matchAll(PANEL)) {
      if (tokens[name] === undefined) continue;
      const spec = `${name}/${alphaOf(bracket, plain)}`;
      sites[spec] ??= new Set();
      sites[spec].add(rel);
    }
  }
  return Object.fromEntries(
    Object.keys(sites)
      .sort()
      .map((spec) => [spec, [...sites[spec]].sort()]),
  );
}

/**
 * Every `text-<token>` painted in the same class string as an alpha panel,
 * keyed by panel. One class string is one element, so a pair found here
 * definitely stacks. A panel that carries text from a *child* element is
 * invisible to this scan and has to be recorded by hand — which is how
 * `bg-sky/40` carried `text-ink-on-sky` at 1.5:1 with three audits green.
 */
export function enumeratePanelText(dir = COMPONENT_DIR, tokens = readColorTokens()) {
  const painted = {};
  for (const file of sourceFiles(dir)) {
    for (const [literal] of stripComments(readFileSync(file, 'utf8')).matchAll(
      LITERAL,
    )) {
      if (!isClassString(literal)) continue;

      const panels = [...literal.matchAll(PANEL)]
        .filter(([, , name]) => tokens[name] !== undefined)
        .map(([, variant, name, bracket, plain]) => ({
          variant,
          spec: `${name}/${alphaOf(bracket, plain)}`,
        }));
      if (panels.length === 0) continue;

      const texts = [...literal.matchAll(TEXT)]
        .filter(([, , name]) => tokens[name] !== undefined)
        .map(([, variant, name, bracket, plain]) => ({
          variant,
          spec:
            bracket === undefined && plain === undefined
              ? name
              : `${name}/${alphaOf(bracket, plain)}`,
        }));

      for (const panel of panels) {
        // A `hover:bg-*` tint is painted in the hover state, so the text on it
        // is the `hover:text-*` the same element declares — not the base one it
        // replaces. Reading them as a pair is what flagged five call sites that
        // swap both at once and never show one over the other.
        const sameState = texts.filter((t) => t.variant === panel.variant);
        const applicable =
          sameState.length > 0
            ? sameState
            : texts.filter((t) => t.variant === '');

        painted[panel.spec] ??= new Set();
        for (const text of applicable) painted[panel.spec].add(text.spec);
      }
    }
  }
  return Object.fromEntries(
    Object.entries(painted).map(([panel, texts]) => [panel, [...texts].sort()]),
  );
}

/** `token` or `token/alpha` split into its two parts. */
export function splitAlpha(spec) {
  const [name, alpha] = spec.split('/');
  return { name, alpha: alpha === undefined ? 1 : Number(alpha) };
}

/**
 * Every surface a panel is mounted on. `"paper"` stands for the whole paper
 * set, so a panel painted on the page is scored against each of them; any
 * other entry names the solid token it sits on. The same panel class can be
 * mounted in both places — `bg-sky/40` is a hero-panel tile on `Runs/Show` and
 * a page-ground tile on `Records` — and the worst mount is the one that counts.
 */
function panelBases(over, tokens, papers) {
  const bases = {};
  for (const mounts of Object.values(over)) {
    for (const mount of mounts) {
      if (mount === 'paper') {
        Object.assign(bases, papers);
        continue;
      }
      bases[mount] = tokens[mount];
    }
  }
  return bases;
}

/**
 * The real ground each registered alpha panel paints, and the text tokens that
 * land on it, reported at the worst mount. This is the audit the `tint` rule
 * only ever did for `-ink` chips.
 */
export function panelGrounds(tokens = readColorTokens(), papers = paperGrounds(tokens)) {
  const rows = [];
  for (const [spec, entry] of Object.entries(KINDS.panel)) {
    if (entry.text.length === 0 || entry.over === undefined) continue;
    const panel = splitAlpha(spec);
    const bases = panelBases(entry.over, tokens, papers);

    for (const text of entry.text) {
      const fg = splitAlpha(text);
      let worst = null;
      for (const [baseName, base] of Object.entries(bases)) {
        const ground = composite(tokens[panel.name], panel.alpha, base);
        const ratio = contrast(composite(tokens[fg.name], fg.alpha, ground), ground);
        if (worst === null || ratio < worst.ratio) {
          worst = { ratio, ground, base: baseName };
        }
      }
      rows.push({ panel: spec, text, ...worst });
    }
  }
  return rows;
}

/**
 * Every ground `--color-<family>-ink` has to clear: the papers, the family's
 * own `-bg` cell when it paints one, and its heaviest alpha tint composited
 * over the darkest paper. Both extras come from the naming convention, so a new
 * cell or a heavier tint is scored as soon as it is recorded.
 */
export function groundsForInk(family, tokens, papers) {
  const grounds = { ...papers };

  const own = `${family}-bg`;
  if (KINDS.scoped.includes(own) && tokens[own] !== undefined) {
    grounds[own] = tokens[own];
  }

  const alpha = KINDS.tint[family];
  if (alpha !== undefined && tokens[family] !== undefined) {
    grounds[`${family}/${alpha} on paper`] = composite(
      tokens[family],
      alpha,
      darkest(papers),
    );
  }

  return grounds;
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
