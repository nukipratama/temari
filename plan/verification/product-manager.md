# Review template — product manager (human, per wave)

**How to use**: worked by a person at the end of each wave, against a built app
(`npm run build`, then `browser-review` or the local stack) seeded with `demo:seed`. Not a subagent
rubric — this one needs someone who knows what the product is supposed to do.

The question this template exists to answer: **did we quietly delete working product?** That is the
single largest risk in this program. The prototype omits a lot of shipped features, and a port that
looks beautiful while losing the Accessories locker is a failure even if every gate is green.

---

## 1. Ledger reconciliation

Open [../ledger.md](../ledger.md). For every row whose **owning slice** landed in this wave:

- Verdict `keep` / `restyle` → the feature is reachable and works. Click it.
- Verdict `defer` → it is still reachable and still works, just unstyled. Confirm it did not
  accidentally get styled, cut, or broken in passing.
- Verdict `cut` → it is *fully* gone: page, route, components, backend, nav entry, and any link
  pointing at it. A cut that leaves a dead link or a 404 reachable from the UI is worse than no cut.

Anything in the wave that is **not** in the ledger is a finding. The ledger is the contract.

## 2. Reachability sweep

Walk [../ia.md](../ia.md)'s route table. For every route:

- Can a user actually get there by tapping, without typing a URL?
- If it is a pushed sub-page, does back go somewhere sensible?
- If it moved between waves, is there a redirect from the old path? The app already keeps a set of
  legacy 301s; new moves join them.

Special attention to routes that were **never in the nav to begin with** — `/accessories`,
`/inbox`, `/settings`, `/profile`, `/race`, `/plan`. These are reached by tapping something. If the
thing they were reached by got redesigned, check the entry point survived.

## 3. States, on a real device viewport

For each screen the wave touched, at 390px wide:

- **empty** — a brand-new user with zero runs. Use a fresh account, not the demo seed.
- **loading** — throttle the network and watch. Does it flash? Does layout shift?
- **failed** — an AI block that failed shows an empty state with a working "Try again".
- **partial** — some data present, some absent. This is the real-world case and the one that breaks.
- **long content** — a 40-character run title, a 12-item list, a very long narration paragraph.

## 4. Half-transitioned seams

A user does not see slices. They see one app. Look for:

- Two visual languages adjacent on the same screen (a ported card next to an unported one).
- A control that exists on one screen and is missing from its twin.
- Terminology drift between a redesigned screen and one still on the old copy.
- Both grounds: does anything read as "we forgot this one" on light, or on dark?

Some seams are **acceptable mid-program** — the point is that they are *known and listed*, not that
they are absent. Record them; they become `W1`/`W2` work.

## 5. Capability degradation

Harder to spot than deletion. For anything the wave touched, ask:

- Did a feature survive but get *worse*? Fewer filter options, a dropped sort, a lost keyboard path,
  a shortcut that no longer exists.
- Did an interaction get slower or more tappy? A one-tap action that became two is a regression even
  if both taps work.
- Did anything become less accessible? Lost focus ring, lost aria-label, a control that is now
  colour-only.

## 6. Wave sign-off

Record in [../README.md](../README.md) §3 notes:

- Findings that **block** the wave (must fix before the next wave starts).
- Findings **deferred** to a named later slice — with the slice id, so they are not lost.
- Anything that should become a ledger amendment.
