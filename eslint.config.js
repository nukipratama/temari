import tseslint from 'typescript-eslint';
import reactPlugin from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';

export default tseslint.config(
    { ignores: ['public/**', 'vendor/**', 'node_modules/**', 'bootstrap/**'] },
    ...tseslint.configs.recommended,
    reactPlugin.configs.flat.recommended,
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        plugins: {
            'react-hooks': reactHooks,
        },
        settings: {
            react: { version: 'detect' },
        },
        rules: {
            'react/react-in-jsx-scope': 'off',
            'react/prop-types': 'off', // TypeScript handles prop validation
            'react-hooks/rules-of-hooks': 'error',
            'react-hooks/exhaustive-deps': 'warn',
            // Block stair-stepped px chains; prefer text-display-* / text-headline-* tokens.
            'no-restricted-syntax': [
                'error',
                {
                    selector: String.raw`Literal[value=/text-\[\d+px\][^"]*\b(sm|md|lg|xl|2xl):text-\[\d+px\]/]`,
                    message: 'Stair-stepped breakpoint sizing detected. Use a text-display-* / text-headline-* token instead — those scale fluidly via clamp().',
                },
                {
                    selector: String.raw`TemplateElement[value.raw=/text-\[\d+px\][^"]*\b(sm|md|lg|xl|2xl):text-\[\d+px\]/]`,
                    message: 'Stair-stepped breakpoint sizing detected. Use a text-display-* / text-headline-* token instead — those scale fluidly via clamp().',
                },
                {
                    selector: String.raw`Literal[value=/\btext-(xs|sm|base|lg|xl|2xl|3xl|4xl|5xl|6xl|7xl)\b[^"]*\b(sm|md|lg|xl|2xl):text-\[\d+px\]/]`,
                    message: 'Mixed Tailwind + hardcoded px stair-step. Use a text-display-* / text-headline-* token instead.',
                },
            ],
        },
    },
);
