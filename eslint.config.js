import { defineConfig } from 'eslint/config';
import tseslint from 'typescript-eslint';
import eslintReact from '@eslint-react/eslint-plugin';
import reactHooks from 'eslint-plugin-react-hooks';
import perfectionist from 'eslint-plugin-perfectionist';

export default defineConfig(
    { ignores: ['public/**', 'vendor/**', 'node_modules/**', 'bootstrap/**'] },
    ...tseslint.configs.recommended,
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        extends: [
            eslintReact.configs['recommended-typescript'],
            reactHooks.configs.flat['recommended-latest'],
        ],
        plugins: {
            perfectionist,
        },
        rules: {
            'perfectionist/sort-imports': [
                'error',
                {
                    type: 'natural',
                    order: 'asc',
                },
            ],
            'no-restricted-imports': [
                'error',
                {
                    paths: [
                        {
                            name: '@/lib/variants',
                            importNames: ['cardVariants'],
                            message: 'cardVariants is retired. Use Card from @/components/ui/card.',
                        },
                        {
                            name: '@/lib/variants',
                            importNames: ['toggleButtonVariants'],
                            message: 'toggleButtonVariants is retired. Use ToggleGroup from @/components/ui/toggle-group.',
                        },
                    ],
                    patterns: [
                        {
                            group: ['**/ui/LegacyCard', './LegacyCard'],
                            message: 'LegacyCard is retired. Use Card from @/components/ui/card.',
                        },
                        {
                            group: ['**/ui/LinkCard', './LinkCard'],
                            message: 'LinkCard is retired. Use Card from @/components/ui/card with render={<Link />}.',
                        },
                        {
                            group: ['**/ui/button', './button'],
                            message: 'The shadcn Button is retired. Use PillButton from @/components/ui/PillButton.',
                        },
                        {
                            group: ['**/ui/toggle', './toggle'],
                            message: 'The shadcn Toggle is retired. Use ToggleGroup from @/components/ui/toggle-group.',
                        },
                        {
                            group: ['**/ui/SectionLabel', './SectionLabel'],
                            message: 'SectionLabel is retired. Use Eyebrow from @/components/ui/Eyebrow.',
                        },
                        {
                            group: ['**/ui/PillLink', './PillLink'],
                            message: 'PillLink is retired. Use PillButton from @/components/ui/PillButton, or apply pillButtonVariants to an <a>.',
                        },
                        {
                            group: ['**/ui/MiniRow', './MiniRow'],
                            message: 'MiniRow is retired. Write the label/value pair inline.',
                        },
                        {
                            group: ['**/ui/ReadMoreToggle', './ReadMoreToggle'],
                            message: 'ReadMoreToggle is retired. Write the toggle inline in the clamped block.',
                        },
                    ],
                },
            ],
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
