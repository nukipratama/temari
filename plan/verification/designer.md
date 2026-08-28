# Review template — designer (human, per wave)

**How to use**: worked by a person at the end of each wave against **built** output. Run
`npm run build` first — the `browser-review` server serves `public/build`, not live source, so an
unbuilt sweep silently screenshots stale assets and you will review the previous wave.

Reference: the frozen prototype (decision 19). Read it at the pinned SHA, never from the working
tree:

```bash
git show prototype-frozen:resources/brand/prototype/src/pages/Today.tsx
```

---

## 1. Fidelity, and the deliberate divergences

Compare each ported screen against its prototype counterpart. Fidelity is the default; these are the
places the app **must not** copy the prototype:

- The 3-way `[data-theme]` review rack, `PhoneFrame`, `Rack.tsx` and the viewport switcher are
  prototype scaffolding. None of it ships.
- `--radius-4xl` on cards. The prototype leans on the top of the radius ladder; the app's ladder is
  re-authored in `F2` and the shipped rounding follows that, not the mockup.
- Hardcoded hex in prototype scaffolding (`#0d0d0f`, `#6a6a72`, `#1c1c1f`, `#4a4a50`) is frame
  chrome, not product colour. None of it ships.
- The prototype is wired to nothing: its mock data is uniformly flattering. Real data is longer,
  emptier and uglier. Judge the design against real seeded data, not the mock.

Divergences that are **intentional** get recorded in the slice doc. An undocumented divergence is a
finding — either it becomes documented or it gets fixed.

## 2. Both grounds

Every check below runs twice: dark (the default) and light. Then a third pass on `system`.

- Nothing is invisible, and nothing is merely *legible-but-wrong* — a value that technically passes
  contrast but reads as a different hierarchy level than intended.
- The **`-ink` tier**: `leaf-ink`, `ember-ink`, `citrus-ink` and the ten `rarity-*-ink` invert
  between grounds by design. On dark, the vivid fill reads and the darkened `-ink` does not. Check
  every place these are used as text.
- Fixed-identity colour is genuinely fixed: `--mood-*`, `--rarity-*` fills, and
  `--color-strava-orange` look the same on both grounds. **The Strava mark is brand-locked** — its
  logo and colours ship exactly as the vendor supplies them, themed only *around*, never recoloured.
  This is a ToS matter, not a taste matter.
- Elevation reads correctly on both. Shadows that carry depth on paper do nothing on a dark ground;
  check that separation survives via surface steps instead.
- `dawn-shift` is scoped to the **light** ground only.

## 3. Rhythm and scale

- Spacing follows the scale. Eyeball the vertical rhythm down a full screen at 390px — the port is
  where a `mt-3` becomes `mt-3.5` for no reason and the page loses its beat.
- Type scale: sizes come from the token ladder, no one-off `text-[13px]`. Line length and leading
  are comfortable on a phone.
- Touch targets ≥ 44px. The prototype draws some controls smaller than is tappable.
- Optical alignment of icons against text baselines after the lucide swap — lucide's metrics differ
  from iconify's and things that were centred will not be.

## 4. Token layer discipline (visual, not lint)

`npm run check:palette` does **not** catch raw hex, and hex applied via an inline `style` prop is
completely ungated. So look:

- Ground-dependent values use the semantic layer (`bg-card`, `text-foreground`, `border-border`).
- Fixed-identity values use the named palette.
- Anything that changed between grounds but should not have, or stayed the same but should not have,
  is a token-layer bug wearing a visual costume.

## 5. Art, on both grounds, client and server

- **Mascot** and the 25 **accessory** SVGs. These are generated from `COLOR` in `build-tokens.mjs`,
  so a token change silently redraws them.
- **Kartu**: rarity chrome, the reveal animation, `KartuMini` in feed context, `FeaturedCardHero`.
- **Share cards**: rendered client-side by `lib/shareCard.ts` and server-side by
  `RunCardImageRenderer.php`. **The two renderers must agree.** Generate one of each for the same run
  and put them side by side — this is the check nothing automated performs.
- Charts: only `F6` designs charts. If a screen slice changed a chart's appearance, that is a finding.

## 6. Motion

- Honours `prefers-reduced-motion`. Turn it on and re-walk the screen.
- No motion library on `bareLayout` / Login — it is enforced framer-motion-free by CI. Motion there
  is plain CSS.
- Transitions do not fight Inertia navigation: no double-animate on back, no flash of the previous
  page's ground.
- **The theme toggle does not flash.** The blocking inline script in `app.blade.php` stamps
  `data-theme` before first paint; verify with a hard reload on each setting, including `system`.

## 7. Wave sign-off

Record in [../README.md](../README.md) §3 notes: blocking findings, findings deferred to a named
slice, and any divergence that should be promoted into a slice doc as a decision.
