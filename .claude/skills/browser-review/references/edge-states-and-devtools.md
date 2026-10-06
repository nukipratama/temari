# Edge states and the operator console

### States the demo does not produce

`demo:seed` marks every Analysis row **done** — honest for a public demo, and exactly why no sweep
ever rendered a pending, processing or failed block. A near-white `.skeleton` shipped on the dark
ground behind that gap and survived three sweeps, because the state that would have shown it never
existed in the seed.

`--with-edge-states` opts in: a failed block and a pending one on the newest activity, and a
processing one on the dashboard briefing. Idempotent, and off by default so the public demo stays
pristine. Run it before an audit that cares about loading, empty or failed states.

It has its **own baseline**, because it makes pages render that otherwise do not: `contrast.mjs`
reports **dark 1** with it on, the "Attempts" header on `/devtools/pulse` at 3.67:1. That is Pulse's
own `<x-pulse::th>` styling showing through our `self-heal-attempts` card, which only has rows once a
failed Analysis exists. Vendor component internals on an operator page, recorded rather than chased.

### The operator console (`/devtools`, `/devtools/design`, `/devtools/narration`, `/pulse`)

**All four are swept by default, and locally they need no password.**
[EnsureDevtoolsAccess](../../../../app/Http/Middleware/EnsureDevtoolsAccess.php) returns early when
the app is not in production, so an unauthenticated request to `/devtools/design` answers **200**.
`/pulse` is a vendor route `route:list --except-vendor` never reports, so it is appended by hand.

This skill used to gate all four on `DEVTOOLS_PASSWORD` being set, which kept `/devtools/design`
out of every audit it runs — the one page that renders the token swatches an audit is most likely
to ask about. Do not reintroduce that gate. `DEVTOOLS_PASSWORD` is needed only to point these
scripts at a production host, where Basic Auth does apply:

```bash
./vendor/bin/sail exec -e DEVTOOLS_PASSWORD=<pw> app node .claude/skills/browser-review/scripts/shoot.mjs
```
