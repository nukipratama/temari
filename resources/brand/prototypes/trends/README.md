# Trends prototype

A clickable proposal for the Trends tab. It is a **review artifact**, the same
kind of thing as `resources/brand/directions.html` — it is not wired to the app,
does not share the app's `package.json`, and nothing here is imported by
`resources/js`.

```bash
cd resources/brand/prototypes/trends
npm install
npm run dev          # http://localhost:7310
```

## What it is built out of

- **shadcn/ui**, `style: base-luma` — installed with
  `npx shadcn@latest init --base base --preset luma`. `--base base` puts
  [Base UI](https://base-ui.com) underneath rather than Radix.
- **Pewter** for colour, shape and type. Every value in the `:root` block of
  `src/index.css` is copied from `resources/brand/directions.html`
  `[data-dir="pewter"]`.
- **Chart.js** via `react-chartjs-2`, matching the two charts the app already
  ships.

## The data

Fixtures, in `src/data/mock.ts`. One invented year of daily TRIMP is the only
input; fitness, fatigue, strain and monotony are then derived from it using the
formulas in `app/Services/Run/Metrics/TrainingLoad.php`, and VDOT using the
Daniels maths in `app/Services/Run/Metrics/VdotEstimator.php`. Nothing is read
from a database.
