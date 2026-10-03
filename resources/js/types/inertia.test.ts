import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import ts from 'typescript';
import { describe, expect, it } from 'vitest';

const ROOT = path.resolve(__dirname, '../../..');
const FIXTURES = path.join(ROOT, 'tests/fixtures');
const PAGE_FIXTURES = 'inertia-props';
const SHARED_FIXTURES = 'inertia-shared-props';
const BASELINE = 'inertia-undeclared-baseline.json';
const PAGES = path.join(ROOT, 'resources/js/pages');
const SHARED_TYPES = path.join(ROOT, 'resources/js/types/inertia.ts');

function fixtureFiles(dir: string, prefix = ''): string[] {
    return readdirSync(dir, { withFileTypes: true }).flatMap((entry) =>
        entry.isDirectory()
            ? fixtureFiles(
                  path.join(dir, entry.name),
                  `${prefix}${entry.name}/`,
              )
            : [`${prefix}${entry.name.replace(/\.json$/, '')}`],
    );
}

function readJson<T>(file: string): T {
    return JSON.parse(readFileSync(path.join(FIXTURES, file), 'utf8')) as T;
}

/** `History.calendar` is the `calendar` view of the `History` page. */
function componentOf(fixture: string): string {
    const slash = fixture.lastIndexOf('/');

    return fixture.slice(0, slash + 1) + fixture.slice(slash + 1).split('.')[0];
}

function parsePath(keyPath: string): string[] {
    return keyPath
        .replaceAll('[]', '.[]')
        .split('.')
        .filter((segment) => segment !== '');
}

const pageFixtures = fixtureFiles(path.join(FIXTURES, PAGE_FIXTURES)).sort();
const sharedFixtures = fixtureFiles(
    path.join(FIXTURES, SHARED_FIXTURES),
).sort();
const components = [...new Set(pageFixtures.map(componentOf))];
const baseline = readJson<Record<string, string[]>>(BASELINE);

const config = ts.readConfigFile(
    path.join(ROOT, 'tsconfig.json'),
    ts.sys.readFile,
);
const { options } = ts.parseJsonConfigFileContent(config.config, ts.sys, ROOT);
const program = ts.createProgram(
    [
        SHARED_TYPES,
        ...components.map((component) => path.join(PAGES, `${component}.tsx`)),
    ],
    options,
);
const checker = program.getTypeChecker();

function moduleExport(file: string, name: string): ts.Symbol | undefined {
    const source = program.getSourceFile(file);
    const moduleSymbol = source && checker.getSymbolAtLocation(source);

    return (
        moduleSymbol && checker.tryGetMemberInModuleExports(name, moduleSymbol)
    );
}

/** Undefined for a page component that takes no props. */
function propsTypeOf(component: string): ts.Type | undefined {
    const page = moduleExport(path.join(PAGES, `${component}.tsx`), 'default');
    const signature =
        page && checker.getTypeOfSymbol(page).getCallSignatures()[0];
    if (signature === undefined) {
        throw new Error(`${component}.tsx has no default-exported component.`);
    }
    const props = signature.getParameters()[0];

    return props && checker.getTypeOfSymbol(props);
}

function sharedPropsType(): ts.Type {
    const shared = moduleExport(SHARED_TYPES, 'SharedProps');
    if (shared === undefined) {
        throw new Error(
            'resources/js/types/inertia.ts exports no SharedProps.',
        );
    }

    return checker.getDeclaredTypeOfSymbol(shared);
}

function members(type: ts.Type): ts.Type[] {
    const nonNull = checker.getNonNullableType(type);

    return nonNull.isUnion() ? nonNull.types : [nonNull];
}

function isOpen(type: ts.Type): boolean {
    return (type.flags & (ts.TypeFlags.Any | ts.TypeFlags.Unknown)) !== 0;
}

function step(type: ts.Type, segment: string, useIndex: boolean): ts.Type[] {
    return members(type).flatMap((member): ts.Type[] => {
        if (isOpen(member)) {
            return [member];
        }
        if (segment === '[]') {
            const element =
                checker.isArrayType(member) || checker.isTupleType(member)
                    ? checker.getIndexTypeOfType(member, ts.IndexKind.Number)
                    : undefined;

            return element ? [element] : [];
        }
        const property =
            segment === '*'
                ? undefined
                : checker.getPropertyOfType(member, segment);
        if (property) {
            return [checker.getTypeOfSymbol(property)];
        }
        if (!useIndex) {
            return [];
        }
        const index =
            (segment === '*'
                ? checker.getIndexTypeOfType(member, ts.IndexKind.Number)
                : undefined) ??
            checker.getIndexTypeOfType(member, ts.IndexKind.String);

        return index ? [index] : [];
    });
}

/** `closedRoot` ignores a root index signature, so SharedProps' `[key: string]: unknown` declares no key. */
function resolve(
    roots: ts.Type[],
    keyPath: string,
    closedRoot: boolean,
): ts.Type[] {
    return parsePath(keyPath).reduce<ts.Type[]>(
        (types, segment, depth) =>
            types.flatMap((type) =>
                step(type, segment, !(closedRoot && depth === 0)),
            ),
        roots,
    );
}

function requiredProperties(type: ts.Type): string[] {
    return checker
        .getPropertiesOfType(type)
        .filter((property) => (property.flags & ts.SymbolFlags.Optional) === 0)
        .map((property) => property.name);
}

/** Required properties of the object at `parent` the payload left out, judged against the union member that fits best. */
function missingRequired(
    types: ts.Type[],
    parent: string,
    emitted: Set<string>,
): string[] {
    const objects = types
        .flatMap(members)
        .filter(
            (type) =>
                !isOpen(type) &&
                !checker.isArrayType(type) &&
                (type.flags & ts.TypeFlags.Object) !== 0,
        );
    if (objects.length === 0) {
        return [];
    }

    const join = (key: string) => (parent === '' ? key : `${parent}.${key}`);
    const gaps = objects.map((type) =>
        requiredProperties(type)
            .filter((key) => !emitted.has(join(key)))
            .map(join),
    );

    return gaps.reduce((best, gap) => (gap.length < best.length ? gap : best));
}

function contractGaps(fixture: string, roots: ts.Type[], closedRoot: boolean) {
    const emitted = readJson<string[]>(`${fixture}.json`);
    const emittedSet = new Set(emitted);
    const allowed = baseline[fixture] ?? [];

    const undeclared = emitted.filter(
        (keyPath) => resolve(roots, keyPath, closedRoot).length === 0,
    );

    const parents = new Set([
        '',
        ...emitted.flatMap((keyPath) => {
            const cut = keyPath.lastIndexOf('.');

            return cut === -1 ? [] : [keyPath.slice(0, cut)];
        }),
    ]);
    const missing = [...parents].flatMap((parent) =>
        missingRequired(resolve(roots, parent, closedRoot), parent, emittedSet),
    );

    return {
        missing,
        staleBaseline: allowed.filter(
            (keyPath) => !undeclared.includes(keyPath),
        ),
        undeclared: undeclared.filter((keyPath) => !allowed.includes(keyPath)),
    };
}

const NO_GAPS = { missing: [], staleBaseline: [], undeclared: [] };
const GAPS_LEGEND = `"undeclared" paths are emitted but absent from the type and not in tests/fixtures/${BASELINE}: declare them, or stop emitting them. "missing" are required by the type but not emitted. "staleBaseline" entries are no longer undeclared: delete them from the baseline.`;

describe('Inertia page props match their TypeScript interfaces', () => {
    it.each(pageFixtures.map((name) => `${PAGE_FIXTURES}/${name}`))(
        '%s',
        (fixture) => {
            const component = componentOf(
                fixture.slice(PAGE_FIXTURES.length + 1),
            );
            const propsType = propsTypeOf(component);

            expect(
                contractGaps(fixture, propsType ? [propsType] : [], false),
                `${fixture} vs resources/js/pages/${component}.tsx: ${GAPS_LEGEND}`,
            ).toEqual(NO_GAPS);
        },
    );
});

describe('Inertia shared props match SharedProps', () => {
    it.each(sharedFixtures.map((name) => `${SHARED_FIXTURES}/${name}`))(
        '%s',
        (fixture) => {
            expect(
                contractGaps(fixture, [sharedPropsType()], true),
                `${fixture} vs SharedProps in resources/js/types/inertia.ts: ${GAPS_LEGEND}`,
            ).toEqual(NO_GAPS);
        },
    );
});

describe(`tests/fixtures/${BASELINE}`, () => {
    it('names only existing fixtures and lists each one sorted without duplicates', () => {
        const fixtures = new Set([
            ...pageFixtures.map((name) => `${PAGE_FIXTURES}/${name}`),
            ...sharedFixtures.map((name) => `${SHARED_FIXTURES}/${name}`),
        ]);

        expect(
            Object.keys(baseline).filter((fixture) => !fixtures.has(fixture)),
            'Baseline entries for fixtures that no longer exist; delete them.',
        ).toEqual([]);
        expect(Object.keys(baseline)).toEqual(Object.keys(baseline).sort());
        for (const [fixture, paths] of Object.entries(baseline)) {
            expect(paths, fixture).toEqual([...new Set(paths)].sort());
        }
    });
});
