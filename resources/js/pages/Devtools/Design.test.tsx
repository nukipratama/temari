import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import Design from './Design';

const TOKENS: Record<string, string> = {
    '--color-ink': '#1a1812',
    '--color-foreground': '#1b1917',
    '--color-surface': '#f5f0e4',
    // Every ground grounds.json calls paper, because the audit scores each one.
    '--color-cream': '#f5f0e4',
    '--color-cream-deep': '#ece2ce',
    '--color-surface-card': '#f5f0e4',
    '--color-surface-elev': '#faf6ec',
    '--color-surface-sunken': '#ece2ce',
    '--color-surface-warm': '#f8f0dd',
    '--color-background': '#f5f0e4',
    '--color-card': '#f5f0e4',
    '--color-popover': '#faf6ec',
    '--color-muted': '#ece2ce',
    '--color-accent': '#f8f0dd',
    '--color-secondary': '#ece2ce',
    '--color-rarity-legendary': '#f5a623',
    '--color-rarity-legendary-ink': '#865b13',
    '--radius-md': '14px',
    '--shadow-e1': '0 1px 2px rgba(58, 45, 20, 0.06)',
    '--spacing-4': '16px',
    '--pad-card': '16px',
};

/**
 * The page discovers token *names* by walking the parsed stylesheets and reads
 * their *values* off `:root`, so a fixture has to provide both halves.
 */
function declareTokens(): () => void {
    const style = document.createElement('style');
    style.textContent = `:root{${Object.keys(TOKENS)
        .map((name) => `${name}:${TOKENS[name]}`)
        .join(';')}}`;
    document.head.append(style);
    for (const [name, value] of Object.entries(TOKENS)) {
        document.documentElement.style.setProperty(name, value);
    }

    return () => {
        style.remove();
        for (const name of Object.keys(TOKENS)) {
            document.documentElement.style.removeProperty(name);
        }
    };
}

let cleanup: (() => void) | null = null;

afterEach(() => {
    cleanup?.();
    cleanup = null;
});

describe('Devtools/Design', () => {
    it('renders every scale section', () => {
        cleanup = declareTokens();
        render(<Design />);

        for (const heading of [
            'Radius',
            'Elevation',
            'Spacing',
            'Type',
            'Contrast audit',
            'Surface audit',
        ]) {
            expect(
                screen.getByRole('heading', { name: heading }),
            ).toBeInTheDocument();
        }
    });

    it('renders swatches from the live stylesheet rather than a copied list', () => {
        cleanup = declareTokens();
        render(<Design />);

        expect(
            screen.getByText(`${Object.keys(TOKENS).length} tokens live`),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'rarity' }),
        ).toBeInTheDocument();
        expect(screen.getByText('#1a1812')).toBeInTheDocument();
        expect(screen.getByText('--pad-card · 16px')).toBeInTheDocument();
    });

    it('audits the live values, outline rule and translucent panels included', () => {
        cleanup = declareTokens();
        render(<Design />);

        // The table shows failures only by default, and these tokens all pass.
        expect(
            screen.getByText(/every pair passes on this ground/),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: /Show all \d+ pairs/ }),
        );

        expect(screen.getByText('Body text')).toBeInTheDocument();
        expect(
            screen.getByText('rarity-legendary fill outline'),
        ).toBeInTheDocument();
        expect(screen.getByText('bg-ink/0.7 panel')).toBeInTheDocument();
        // Passed/total rather than a literal count: grounds.json gains rows as
        // screens land, and what this asserts is that none of them fail.
        expect(screen.getByText(/^contrast (\d+)\/\1$/)).toBeInTheDocument();
    });

    it('says so when no custom properties are readable', () => {
        render(<Design />);

        expect(
            screen.getByText(/No custom properties readable/),
        ).toBeInTheDocument();
    });

    it("renders Temari's face at every shipped size against the live tokens", () => {
        cleanup = declareTokens();
        const { container } = render(<Design />);

        for (const heading of ["Temari's face", "Temari's face on sky"]) {
            expect(
                screen.getByRole('heading', { name: heading }),
            ).toBeInTheDocument();
        }

        const faces = Array.from(
            container.querySelectorAll('[data-face-icon]'),
        ).map((el) => el.getAttribute('width'));
        for (const size of [
            '26',
            '34',
            '36',
            '40',
            '42',
            '48',
            '56',
            '64',
            '72',
        ]) {
            expect(faces).toContain(size);
        }
    });

    it('leaves a hook for the card art sections', () => {
        cleanup = declareTokens();
        render(<Design />);

        expect(
            screen.getByText('Reserved for the card art slice'),
        ).toBeInTheDocument();
    });
});
