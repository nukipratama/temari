# T3 — The empty-card tone

Raised inside `PS12` and left standing there rather than folded in silently: `Card`'s `empty` tone
is a dashed placeholder, and the prototype draws no dashed border anywhere.

## The finding is stronger than "dashed vs solid"

The prototype does not give empty states a distinct treatment **at all**. Its `EmptyInboxCard`,
`NoRunsCard`, `NoRaceState` and `NoPlanState` are drawn with exactly the same class string as its
ordinary cards — `rounded-[14px] border border-border-strong bg-card shadow-e1` — the same one its
`RecapCard`, race cards and plan cards use. There is no placeholder look to port, because in the
prototype an empty state is just a card.

The app's `empty` tone was `border border-dashed border-border-strong bg-card/40`: a dashed edge
and a 40%-opacity fill, both invented here.

## What ships

`empty` becomes `border border-border-strong bg-card shadow-e1`.

It stays a distinct tone rather than being deleted in favour of `card`, because the two are not the
same: `cardVariants.card` uses `border-border`, and the prototype's cards — empty ones included —
use the heavier `border-border-strong`. Collapsing `empty` into `card` would have quietly moved the
empty states onto the lighter border. That the app's *ordinary* card tone uses `border-border`
while the ported screens hand-roll `border-border-strong` is a real inconsistency, but a
pre-existing one and not this slice's to settle.

`bg-card/40` was the only thing painting `card/0.4`, so that registration comes out of
`grounds.json`.

## Scope

Nine `EmptyPanel` call sites and `aiusage/EmptyState.tsx`. The last is an operator page, which P20
leaves outside the parity surface — but it consumes the same shared tone, so it changes with it.
That is the tone being consistent, not an operator page being restyled.

## Acceptance criteria

1. No dashed border remains on any empty state.
2. `card/0.4` is gone from `grounds.json` and `DesignTokenContrastTest` is green, which fails closed
   both ways.
3. `./vendor/bin/sail composer check` green (`--no-tia` on pest).

## Verification notes

- The prototype's own class strings were read rather than summarised, and the match to its ordinary
  cards is byte-for-byte — which is the whole basis for the change.
- Three tests asserted `border-dashed`; each was updated from the suite's own failure output, and
  `EmptyPanel`'s now asserts the *absence* of a dashed border so the old treatment cannot creep
  back.
