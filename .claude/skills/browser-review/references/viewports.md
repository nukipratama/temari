## Viewport matrix (default)

The app has **one** nav chrome at every width — `MobileTopBar` + `MobileBottomNav`. The port deleted
the desktop `TopNav`, so no viewport here swaps chrome; what they cover is width-driven layout and
the two type steps (`1280px` -> 19.2px, `2048px` -> 21.6px):

| key | size | what it covers | in default sweep? |
|-----|------|-----------|--------------------|
| `mobile`  | 390×844  (iPhone 13)   | base type (16px) | yes |
| `se`      | 320×568  (iPhone SE)   | base type (16px) | yes — narrowest real device, catches width-driven bugs `mobile` misses |
| `tablet`  | 834×1112 (iPad portrait) | base type (16px) | no — nothing disagrees with `mobile` here, opt in explicitly |
| `laptop`  | 1920×1080              | first type step (19.2px), past every column breakpoint | yes |
| `desktop` | 2560×1440 (2K)         | **second type step (21.6px)** — the only viewport past 2048px | yes |

Default is `mobile,se,laptop,desktop` — two phones and the two real desktop sizes. `laptop` and
`desktop` differ only by the 2048px type step, which is the point: one takes 19.2px, the other
21.6px. The old 1280 and 1536 entries are gone because 1920 is already past every breakpoint they
tested (`lg`, the 1280 column widening, and the `2xl` page cap), so they only cost screenshots.

`tablet` is dropped from the default because nothing disagrees with `mobile` there. `se`, in
contrast, is kept despite sharing `mobile`'s chrome: its narrower 320px width has caught real
overflow that 390px missed entirely — a CSS grid track sized to its widest child instead of shrinking
to fit, a fluid font clamp whose floor was tuned for a wider column and silently ellipsis-truncated
real values. Those are width-driven bugs, not breakpoint-driven ones, so they don't reproduce at
390px. Narrow with `VIEWPORTS=mobile`, or take the full five-way matrix with
`VIEWPORTS=mobile,se,tablet,laptop,desktop` before a release.

Nothing covers 900–1279px, and nothing did before either — worth knowing rather than assuming the
matrix is exhaustive.
