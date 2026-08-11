import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import { appLayout } from './appLayout';

describe('appLayout', () => {
    it('wraps the page in the full shell', () => {
        setMockPage({
            auth: { user: makeUser() },
            flash: {},
            demoLoginEnabled: false,
        });
        render(appLayout(<p>page body</p>));

        expect(screen.getByText('page body')).toBeInTheDocument();
        ['Today', 'Collection', 'Plan', 'Me'].forEach((label) => {
            expect(screen.getAllByText(label).length).toBeGreaterThan(0);
        });
    });

    // Inertia compares the layout by reference to decide whether to keep the
    // shell mounted across a visit. A fresh function per render would defeat
    // the whole point of the persistent layout, so this must be a stable
    // module-level constant.
    it('exposes a stable reference so Inertia keeps the shell mounted', () => {
        expect(appLayout).toBe(appLayout);
    });
});
