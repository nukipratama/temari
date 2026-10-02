import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { setMockPage } from '@/test/setup';

import AiOutageBanner from './AiOutageBanner';

const base = {
    auth: { user: null },
    flash: {},
    demoLoginEnabled: false,
} as const;

describe('AiOutageBanner', () => {
    it('renders nothing when the pipeline is healthy', () => {
        setMockPage({ ...base, aiPaused: false });
        const { container } = render(<AiOutageBanner />);
        expect(container.firstChild).toBeNull();
    });

    it('renders nothing when the prop is absent', () => {
        setMockPage({ ...base });
        const { container } = render(<AiOutageBanner />);
        expect(container.firstChild).toBeNull();
    });

    it('shows a soft resting message when paused', () => {
        setMockPage({ ...base, aiPaused: true });
        render(<AiOutageBanner />);
        expect(
            screen.getByText(
                "temari's catching her breath. your notes aren't lost, they'll catch up on their own.",
            ),
        ).toBeInTheDocument();
    });
});

const sources = import.meta.glob<string>(
    [
        '/resources/js/**/*.{ts,tsx}',
        '!/resources/js/**/*.test.{ts,tsx}',
        '!/resources/js/test/**',
    ],
    { query: '?raw', import: 'default', eager: true },
);
const sourceByPath = new Map(
    Object.entries(sources).map(([key, text]) => [
        key.replace(/^\/resources\/js\//, ''),
        text,
    ]),
);

function resolveImport(from: string, specifier: string): string | null {
    let base: string;
    if (specifier.startsWith('@/')) {
        base = specifier.slice(2);
    } else if (specifier.startsWith('.')) {
        const parts = from.split('/').slice(0, -1);
        for (const segment of specifier.split('/')) {
            if (segment === '..') {
                parts.pop();
            } else if (segment !== '.') {
                parts.push(segment);
            }
        }
        base = parts.join('/');
    } else {
        return null;
    }

    return (
        [
            base,
            `${base}.tsx`,
            `${base}.ts`,
            `${base}/index.tsx`,
            `${base}/index.ts`,
        ].find((candidate) => sourceByPath.has(candidate)) ?? null
    );
}

function reachableFrom(entry: string): Set<string> {
    const seen = new Set<string>();
    const queue = [entry];
    while (queue.length > 0) {
        const path = queue.pop()!;
        if (seen.has(path)) {
            continue;
        }
        seen.add(path);
        const text = sourceByPath.get(path) ?? '';
        for (const match of text.matchAll(
            /(?:import|export)\s+(?!type\s)(?:[^;'"]*?\sfrom\s+)?['"]([^'"]+)['"]/g,
        )) {
            const resolved = resolveImport(path, match[1]);
            if (resolved !== null) {
                queue.push(resolved);
            }
        }
    }

    return seen;
}

describe('AiOutageBanner placement', () => {
    const pages = [...sourceByPath.keys()].filter((path) =>
        path.startsWith('pages/'),
    );

    it('is mounted by exactly the pages that render narration blocks', () => {
        const mismatched = pages.filter((page) => {
            const reach = reachableFrom(page);
            return (
                reach.has('components/temari/AnalysisStatus.tsx') !==
                reach.has('components/AiOutageBanner.tsx')
            );
        });

        expect(
            mismatched,
            'A page that renders AnalysisStatus must mount AiOutageBanner, and a page without narration must not',
        ).toEqual([]);
    });

    it('keeps the narration screens mounting it', () => {
        const mounting = pages.filter((page) =>
            reachableFrom(page).has('components/AiOutageBanner.tsx'),
        );

        expect(mounting).toEqual(
            expect.arrayContaining([
                'pages/Home.tsx',
                'pages/Plan.tsx',
                'pages/Profile.tsx',
                'pages/Trends.tsx',
                'pages/History.tsx',
                'pages/Runs/Show.tsx',
            ]),
        );
        expect(mounting).not.toContain('pages/Settings/Index.tsx');
        expect(mounting).not.toContain('pages/Inbox.tsx');
        expect(mounting).not.toContain('pages/Race.tsx');
    });
});
