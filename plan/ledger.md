# Reconciliation ledger

Every shipped feature the prototype's eleven mockups omit, ruled `keep` / `restyle` / `cut` / `defer`
by the user during `L0`. Per decision 1, cutting is permitted, and it was exercised three times here
— this is not a rubber-stamp keep-everything ledger.

**All verdicts below are final** (put to the user via AskUserQuestion, 2026-08-28).

| feature | where it lives | verdict | rationale | owning slice |
|---|---|---|---|---|
| **Kartu** (collectible run card) | [resources/js/components/card/](../resources/js/components/card/) (20 files), `lib/shareCard.ts`, `lib/runcard.ts`, `RunCardImageRenderer.php`, `Rarity` enum, `RunCard` model | **restyle** | Core identity feature, not incidental — gets deliberate design work rather than a mechanical re-skin | `F5` |
| **Accessories locker** | `pages/Collection/Accessories.tsx`, `AccessoryController.php`, `EquipAccessoryRequest.php`, `EquippedAccessories.php`, 25 SVGs under `resources/brand/accessories/` | **cut** | Full removal: page, `/accessories` + `/api/accessories/equip` routes, controller, request, service, SVG generation, nav entry, every inbound link | `W1` (routes) + `W2` (backend/component deletion) |
| **Feed filters** | `HistoryFilter.tsx`/`HistoryTabs.tsx`, `useFeedFilters.ts`, `FeedFilters.php`, `FeedQuery.php` | **cut** | Filtering removed from the History/feed screens entirely | `S7` |
| **Journey strip** | `components/activities/JourneyStrip.tsx` | **cut** | Single minor component, no dedicated backend, unclear standalone value | `S7` |
| **Persona mix** | `PersonaBar.tsx` (Profile), `PersonaMixTool.php` | **restyle** | Distinctive identity feature on the redesigned Profile page, deliberate treatment | `S10` |
| **Badge / milestone system** | `Badge` enum, `UserUnlock` model, `BadgeEvaluator.php`, `DetectActivityMilestonesAction.php`, `GrantSeasonUnlocksAction.php`, `GrantEligibleUnlocksAction.php`, `StravaSyncBadge.tsx`, `UnlockToast.tsx`, `AccessoryUnlockModal.tsx`, `UnlockGrantedNotification.php` | **restyle** | Real design work, split across Profile (where milestones display) and wherever unlock toasts fire | `S10` (display) + `S3` (toast firing) |
| **Leaflet route maps** | `RouteMap.tsx`, `MapWeatherPanel.tsx`, `PolylineDecoder/Encoder/Projector.php` | **keep**, mechanical only | Core to what a run detail page is; re-skinned via the token sweep, not redesigned from scratch | `S8` |
| **Run lenses** | `RunLenses.tsx` | **keep**, mechanical only | Token-swept, folded into `S8` without a dedicated pass | `S8` |
| **Relative effort** | `RelativeEffort.php`, display in `Runs/Show.tsx` / `useRunShow.ts` | **keep**, mechanical only | A stat display, not a screen needing its own design pass | `S8` |
| **Dawn-shift** | `useDawnShift.ts`, `data-time-of-day` on `<body>`, consumers in `designTokens.ts` / `shareCard.ts` | **keep**, light-ground only | Confirms decision 6 exactly: scoped to the light ground, no meaning on the Sky (dark) ground | `F4` / `F5` |
| **Legal, Devtools, Devtools/Design, AiUsage** (+10 `aiusage/` components) | `pages/Legal/Document.tsx`, `pages/Devtools.tsx`, `pages/Devtools/Design.tsx`, `pages/AiUsage.tsx` + `aiusage/` | **restyle** | Given a real design pass, not just token-swept — upgraded from the original mechanical-only assumption | `S12` |

## Coupling this ledger surfaced

**`AccessoryUnlockModal.tsx` is shared between the two gamification features that got opposite
verdicts.** It fires for both accessory unlocks (now cut) and, per the explore agent's mapping,
sits alongside the badge/milestone unlock flow. `W2` (deleting the Accessories backend) and `S10`
(restyling badge/milestone display) must coordinate: confirm whether `AccessoryUnlockModal` is
accessory-specific (delete it with the rest of `W2`'s scope) or a generic unlock-celebration
component also used for badges (keep and rename, own it in `S10`). Resolve this when `W2` starts —
do not delete blind.

## Consequences of the cuts

- **Legacy redirects** `/target`, `/goals`, `/aksesori` currently 301 to `/accessories`. Per the IA
  ruling in [ia.md](ia.md), these are **removed**, not repointed — `W1`'s job.
- `GrantEligibleUnlocksAction.php` / `GrantSeasonUnlocksAction.php` likely grant both badge and
  accessory unlocks through the shared `UserUnlock` model. `W2` narrows their scope to badges only;
  confirm no accessory-only code path survives as dead weight.
- Feed filters and journey strip cuts simplify `S7`'s scope relative to the original plan — see the
  updated [19-S7-history.md](slices/19-S7-history.md).
