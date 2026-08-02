import type { ReactNode } from 'react';

import AppShell from '@/layouts/AppShell';

/**
 * Inertia persistent layouts. A page sets `Page.layout = appLayout` instead of
 * wrapping its own render in `<AppShell>`, which is what keeps the shell — nav,
 * top bar, banners, MotionConfig, useDawnShift, the celebration overlays —
 * **mounted across navigations** rather than torn down and rebuilt on every
 * visit. That remount was the single biggest reason navigation read as a page
 * reload instead of an app, and a stable shell is also what lets the content
 * region alone show a loading state while the shell stays put.
 *
 * A module-level constant on purpose: Inertia compares the layout by
 * reference, so an inline `page => <AppShell>{page}</AppShell>` at each call
 * site would be a new function every render and defeat the persistence.
 *
 * The standalone counterpart is `bareLayout` in `BareShell.tsx`, kept in its
 * own module so that importing it does not drag `AppShell` — and with it
 * framer-motion — onto Login's first paint.
 */
export const appLayout = (page: ReactNode) => <AppShell>{page}</AppShell>;
