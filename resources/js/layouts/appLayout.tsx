import type { ReactNode } from 'react';

import AppShell from '@/layouts/AppShell';

/**
 * Inertia persistent layouts. A page sets `Page.layout = appLayout` instead of
 * wrapping its own render in `<AppShell>`, which keeps the shell mounted across
 * navigations.
 *
 * A module-level constant on purpose: Inertia compares the layout by reference,
 * so an inline `page => <AppShell>{page}</AppShell>` would defeat persistence.
 *
 * The standalone counterpart is `bareLayout` in `BareShell.tsx`, kept in its
 * own module so importing it does not drag `AppShell` onto Login's first paint.
 */
export const appLayout = (page: ReactNode) => <AppShell>{page}</AppShell>;
