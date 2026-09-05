# Server-side card fonts

`RunCardImageRenderer` builds an SVG whose `font-family` names the same three
families the browser uses, and librsvg resolves those through **fontconfig** —
so they have to exist as real font files inside the image or every `<text>`
silently falls back to the default sans and the rendered card stops matching
the one the client canvas exports.

`JetBrains Mono` comes from Alpine (`font-jetbrains-mono`). The other two are
not packaged for Alpine, so they are vendored here and copied into
`/usr/share/fonts/temari` by the `Dockerfile` (both the `dev` and runtime
stages).

| File | Family | Upstream | Pinned commit |
|---|---|---|---|
| `Fraunces-Italic.ttf` | `Fraunces` | [googlefonts/fraunces](https://github.com/googlefonts/fraunces) `fonts/Fraunces-Italic[SOFT,WONK,opsz,wght].ttf` | `02ab61143d800c37da148bb20382e7e2459a56af` |
| `PlusJakartaSans.ttf` | `Plus Jakarta Sans` | [tokotype/PlusJakartaSans](https://github.com/tokotype/PlusJakartaSans) `fonts/variable/PlusJakartaSans[wght].ttf` | `5854bde9039ce212dd34a8ab369aae66ef6e4b6b` |

Both are variable fonts, kept variable on purpose: the static Fraunces
instances carry family names like `Fraunces 144pt`, which would not match the
plain `Fraunces` the SVG asks for. Both are licensed **SIL OFL 1.1**.

Only the italic cut of Fraunces is vendored, because the card uses Fraunces in
exactly one place (the run name, `font-style="italic"`). Add the roman file
from the same upstream path if a non-italic Fraunces is ever needed.

After changing anything here, rebuild and re-verify that librsvg actually
*selects* the font rather than merely having it installed — render a card and
look at it. `fc-list : family` proving the family exists is not the same test.
