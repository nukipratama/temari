import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import ts from 'typescript';
import { describe, expect, it } from 'vitest';

const ROOT = path.resolve(__dirname, '../../..');
const FIXTURES = path.join(ROOT, 'tests/fixtures/inertia-props');
const PAGES = path.join(ROOT, 'resources/js/pages');

function fixtureFiles(dir: string, prefix = ''): string[] {
    return readdirSync(dir, { withFileTypes: true }).flatMap((entry) =>
        entry.isDirectory()
            ? fixtureFiles(
                  path.join(dir, entry.name),
                  `${prefix}${entry.name}/`,
              )
            : [`${prefix}${entry.name}`],
    );
}

/** `History.calendar.json` is the `calendar` view of the `History` page. */
function componentOf(fixture: string): string {
    const base = fixture.replace(/\.json$/, '');
    const slash = base.lastIndexOf('/');

    return base.slice(0, slash + 1) + base.slice(slash + 1).split('.')[0];
}

function parsePath(keyPath: string): string[] {
    return keyPath
        .replaceAll('[]', '.[]')
        .split('.')
        .filter((segment) => segment !== '');
}

const fixtures = fixtureFiles(FIXTURES).sort();
const components = [...new Set(fixtures.map(componentOf))];

const config = ts.readConfigFile(
    path.join(ROOT, 'tsconfig.json'),
    ts.sys.readFile,
);
const { options } = ts.parseJsonConfigFileContent(config.config, ts.sys, ROOT);
const program = ts.createProgram(
    components.map((component) => path.join(PAGES, `${component}.tsx`)),
    options,
);
const checker = program.getTypeChecker();

/** Undefined for a page component that takes no props. */
function propsTypeOf(component: string): ts.Type | undefined {
    const source = program.getSourceFile(path.join(PAGES, `${component}.tsx`));
    const moduleSymbol = source && checker.getSymbolAtLocation(source);
    const page =
        moduleSymbol &&
        checker.tryGetMemberInModuleExports('default', moduleSymbol);
    const signature =
        page && checker.getTypeOfSymbol(page).getCallSignatures()[0];
    if (signature === undefined) {
        throw new Error(`${component}.tsx has no default-exported component.`);
    }
    const props = signature.getParameters()[0];

    return props && checker.getTypeOfSymbol(props);
}

function members(type: ts.Type): ts.Type[] {
    const nonNull = checker.getNonNullableType(type);

    return nonNull.isUnion() ? nonNull.types : [nonNull];
}

function isOpen(type: ts.Type): boolean {
    return (type.flags & (ts.TypeFlags.Any | ts.TypeFlags.Unknown)) !== 0;
}

function step(type: ts.Type, segment: string): ts.Type[] {
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
        const index =
            (segment === '*'
                ? checker.getIndexTypeOfType(member, ts.IndexKind.Number)
                : undefined) ??
            checker.getIndexTypeOfType(member, ts.IndexKind.String);

        return index ? [index] : [];
    });
}

function resolve(roots: ts.Type[], keyPath: string): ts.Type[] {
    return parsePath(keyPath).reduce<ts.Type[]>(
        (types, segment) => types.flatMap((type) => step(type, segment)),
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

describe('Inertia page props match their TypeScript interfaces', () => {
    it.each(fixtures)('%s', (fixture) => {
        const component = componentOf(fixture);
        const propsType = propsTypeOf(component);
        const roots = propsType ? [propsType] : [];
        const emitted: string[] = JSON.parse(
            readFileSync(path.join(FIXTURES, fixture), 'utf8'),
        );
        const emittedSet = new Set(emitted);

        const undeclared = emitted.filter(
            (keyPath) => resolve(roots, keyPath).length === 0,
        );

        const parents = new Set([
            '',
            ...emitted.flatMap((keyPath) => {
                const cut = keyPath.lastIndexOf('.');

                return cut === -1 ? [] : [keyPath.slice(0, cut)];
            }),
        ]);
        const missing = [...parents].flatMap((parent) =>
            missingRequired(resolve(roots, parent), parent, emittedSet),
        );

        expect(
            { undeclared, missing },
            `${fixture} vs resources/js/pages/${component}.tsx: "undeclared" paths are emitted by the controller but absent from the props type; "missing" are required by the type but not emitted.`,
        ).toEqual({ undeclared: [], missing: [] });
    });
});
