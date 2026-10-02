import ts from 'typescript';
import { describe, expect, it } from 'vitest';

const sources = import.meta.glob(
    ['../components/**/*.{ts,tsx}', '../pages/**/*.{ts,tsx}'],
    {
        eager: true,
        import: 'default',
        query: '?raw',
    },
) as Record<string, string>;

const EXCLUDED = [
    /\.test\.tsx?$/,
    /^\.\.\/components\/ui\//,
    /^\.\.\/components\/narration\//,
    /^\.\.\/pages\/Legal\//,
    /^\.\.\/pages\/Narration\//,
    /^\.\.\/pages\/Devtools/,
];

const PROPER_NOUNS = new Set([
    'React',
    'Strava',
    'Telegram',
    'Garmin',
    'Coros',
    'Apple',
    'Google',
    'Jakarta',
]);

const NON_PROSE_ATTRIBUTES = new Set([
    'className',
    'alt',
    'd',
    'eyebrow',
    'href',
    'id',
    'key',
    'name',
    'type',
    'viewBox',
]);

const UPPERCASED_TAGS = new Set([
    'BackLink',
    'Eyebrow',
    'FieldGroup',
    'FieldLabel',
    'GroupLabel',
    'Head',
    'LensLabel',
    'NumberField',
    'SectionLabel',
    'TemariTake',
    'TimeInZoneBar',
]);

const MONO_LABELS = new Set([
    'Longest run',
    'Monthly Recap',
    'No date',
    'This Week',
    'Time in zone · last 12 weeks',
    'Total km',
    'Total runs',
    'Weekly Recap',
]);

const UPPERCASING_CLASSES = /\buppercase\b|\btext-label-|\bsr-only\b/;

const COMPARISON_OPERATORS = [
    ts.SyntaxKind.EqualsEqualsToken,
    ts.SyntaxKind.EqualsEqualsEqualsToken,
    ts.SyntaxKind.ExclamationEqualsToken,
    ts.SyntaxKind.ExclamationEqualsEqualsToken,
];

function capitalisedWord(text: string): string | null {
    for (const match of text
        .trim()
        .matchAll(/(?:^|[.!?]\s+)([A-Z][a-z]+)|\b(Temari)\b/g)) {
        const word = match[1] ?? match[2];
        if (word === 'Temari' || !PROPER_NOUNS.has(word)) {
            return word;
        }
    }

    return null;
}

function isComparisonOperand({ parent }: ts.Node): boolean {
    return (
        (ts.isBinaryExpression(parent) &&
            COMPARISON_OPERATORS.includes(parent.operatorToken.kind)) ||
        ts.isCaseClause(parent) ||
        ts.isElementAccessExpression(parent)
    );
}

function isModuleSpecifier({ parent }: ts.Node): boolean {
    return (
        ts.isImportDeclaration(parent) ||
        ts.isExportDeclaration(parent) ||
        ts.isLiteralTypeNode(parent)
    );
}

function chromeStrings(file: string, source: string): string[] {
    const sf = ts.createSourceFile(
        file,
        source,
        ts.ScriptTarget.Latest,
        true,
        ts.ScriptKind.TSX,
    );
    const constants = new Map<string, string>();
    const found: string[] = [];

    const collectConstants = (node: ts.Node) => {
        if (
            ts.isVariableDeclaration(node) &&
            ts.isIdentifier(node.name) &&
            node.initializer &&
            ts.isStringLiteral(node.initializer)
        ) {
            constants.set(node.name.text, node.initializer.text);
        }
        ts.forEachChild(node, collectConstants);
    };
    collectConstants(sf);

    const classNameOf = (attributes: ts.JsxAttributes): string => {
        const attribute = attributes.properties.find(
            (property) =>
                ts.isJsxAttribute(property) &&
                property.name.getText() === 'className',
        );
        const text = attribute?.getText() ?? '';

        return [
            text,
            ...text.split(/\W+/).map((token) => constants.get(token) ?? ''),
        ].join(' ');
    };

    const rendersUppercase = (node: ts.Node): boolean => {
        for (
            let current: ts.Node | undefined = node;
            current;
            current = current.parent
        ) {
            if (
                ts.isJsxElement(current) &&
                (UPPERCASING_CLASSES.test(
                    classNameOf(current.openingElement.attributes),
                ) ||
                    UPPERCASED_TAGS.has(
                        current.openingElement.tagName.getText(),
                    ))
            ) {
                return true;
            }
        }

        return false;
    };

    const note = (node: ts.Node, text: string) => {
        const word = capitalisedWord(text);
        if (
            word !== null &&
            !MONO_LABELS.has(text) &&
            !rendersUppercase(node)
        ) {
            const line =
                sf.getLineAndCharacterOfPosition(node.getStart()).line + 1;
            found.push(
                `${file.replace('../', '')}:${line}  ${word}: ${text.replace(/\s+/g, ' ').trim().slice(0, 70)}`,
            );
        }
    };

    const visit = (node: ts.Node, insideExpression: boolean) => {
        if (
            ts.isJsxSelfClosingElement(node) &&
            (UPPERCASED_TAGS.has(node.tagName.getText()) ||
                UPPERCASING_CLASSES.test(classNameOf(node.attributes)))
        ) {
            return;
        }
        if (ts.isJsxText(node)) {
            note(node, node.text);
        } else if (ts.isJsxAttribute(node)) {
            const name = node.name.getText();
            if (
                NON_PROSE_ATTRIBUTES.has(name) ||
                name.startsWith('aria-') ||
                name.startsWith('data-')
            ) {
                return;
            }
            if (node.initializer && ts.isStringLiteral(node.initializer)) {
                note(node.initializer, node.initializer.text);
                return;
            }
        } else if (
            (ts.isStringLiteral(node) ||
                ts.isNoSubstitutionTemplateLiteral(node) ||
                ts.isTemplateHead(node)) &&
            !isComparisonOperand(node) &&
            (insideExpression || /^[A-Z][a-z]+[\s…]/.test(node.text)) &&
            !isModuleSpecifier(node)
        ) {
            note(node, node.text);
        }
        ts.forEachChild(node, (child) =>
            visit(child, insideExpression || ts.isJsxExpression(node)),
        );
    };
    visit(sf, false);

    return found;
}

describe('lowercase app chrome', () => {
    it('flags a capitalised sentence start, a capitalised second sentence and the capitalised name', () => {
        expect(capitalisedWord('Skip this session')).toBe('Skip');
        expect(capitalisedWord('all set. Try again')).toBe('Try');
        expect(capitalisedWord('ask Temari')).toBe('Temari');
    });

    it('leaves proper nouns, acronyms and lowercase text alone', () => {
        expect(capitalisedWord('skip this session')).toBeNull();
        expect(capitalisedWord('Strava is connected')).toBeNull();
        expect(capitalisedWord('HR zones, Z2 and PR')).toBeNull();
        expect(capitalisedWord("i'll answer from what's here")).toBeNull();
    });

    it('has no capitalised chrome text in components/ or pages/', () => {
        const offenders = Object.entries(sources)
            .filter(([file]) => !EXCLUDED.some((pattern) => pattern.test(file)))
            .flatMap(([file, source]) => chromeStrings(file, source));

        expect(
            offenders,
            `Chrome is lowercase (docs/voice-and-tone.md). Lowercase these, or add a proper noun to PROPER_NOUNS:\n  ${offenders.join('\n  ')}`,
        ).toEqual([]);
    });
});
