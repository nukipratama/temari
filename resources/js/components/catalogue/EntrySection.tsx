import type { CatalogueItem, CatalogueMatrix } from '@/lib/catalogue';

import GroundFrame from '@/components/catalogue/GroundFrame';
import UsageSnippet from '@/components/catalogue/UsageSnippet';
import Eyebrow from '@/components/ui/Eyebrow';
import { SharedPropsOverrideContext } from '@/hooks/useSharedProps';

const NO_OVERRIDE = {};

/** One catalogue entry: what it is, how to call it, each named state and its variant matrix. */
export default function EntrySection({
    item,
}: Readonly<{ item: CatalogueItem }>) {
    return (
        <section
            id={item.id}
            aria-labelledby={`${item.id}-title`}
            className="scroll-mt-6"
        >
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2
                    id={`${item.id}-title`}
                    className="font-mono text-base font-bold text-foreground"
                >
                    {item.name}
                </h2>
                <span className="text-meta">{item.path}</span>
            </div>
            <p className="mt-1.5 max-w-[72ch] font-sans text-sm leading-relaxed text-text-2">
                {item.description}
            </p>
            <div className="mt-3">
                <UsageSnippet code={item.usage} />
            </div>

            {item.states.map((state) => (
                <div key={state.name} className="mt-5">
                    <Eyebrow token="micro" tone="ink-3" as="h3">
                        {state.name}
                    </Eyebrow>
                    <div className="mt-2">
                        <SharedPropsOverrideContext
                            value={state.sharedProps ?? NO_OVERRIDE}
                        >
                            <GroundFrame overlay={state.overlay}>
                                {state.render()}
                            </GroundFrame>
                        </SharedPropsOverrideContext>
                    </div>
                </div>
            ))}

            {item.matrix && <VariantMatrix matrix={item.matrix} />}
        </section>
    );
}

function VariantMatrix({ matrix }: Readonly<{ matrix: CatalogueMatrix }>) {
    const omitted = matrix.omitted ?? {};
    const [rowAxis, columnAxis] = Object.keys(matrix.axes);
    const shown = (value: string) => !(value in omitted);
    const rows = matrix.axes[rowAxis].filter(shown);
    const columns =
        columnAxis === undefined ? [] : matrix.axes[columnAxis].filter(shown);

    const variant = (row: string, column: string | null) =>
        column === null
            ? { [rowAxis]: row }
            : { [rowAxis]: row, [columnAxis]: column };

    return (
        <div className="mt-5">
            <Eyebrow token="micro" tone="ink-3" as="h3">
                {columnAxis === undefined
                    ? `variants · ${rowAxis}`
                    : `variants · ${rowAxis} × ${columnAxis}`}
            </Eyebrow>
            <div className="mt-2">
                <GroundFrame>
                    <div className="overflow-x-auto">
                        <table className="border-collapse">
                            {columns.length > 0 && (
                                <thead>
                                    <tr>
                                        <th aria-label={rowAxis} />
                                        {columns.map((column) => (
                                            <th
                                                key={column}
                                                scope="col"
                                                className="px-2 pb-2 text-left text-meta font-normal"
                                            >
                                                {column}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                            )}
                            <tbody>
                                {rows.map((row) => (
                                    <tr key={row}>
                                        <th
                                            scope="row"
                                            className="pr-3 text-left text-meta font-normal"
                                        >
                                            {row}
                                        </th>
                                        {(columns.length > 0
                                            ? columns
                                            : [null]
                                        ).map((column) => (
                                            <td
                                                key={column ?? row}
                                                className="px-2 py-1.5"
                                            >
                                                {matrix.render(
                                                    variant(row, column),
                                                )}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </GroundFrame>
            </div>
            {Object.entries(omitted).map(([value, reason]) => (
                <p key={value} className="mt-1.5 text-meta">
                    not shown · {value}: {reason}
                </p>
            ))}
        </div>
    );
}
