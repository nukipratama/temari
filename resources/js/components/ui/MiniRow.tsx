/**
 * The prototype's `MiniRow`: one hairline-separated label/value pair inside
 * the pair of mini cards at the foot of Today's stats disclosure.
 */
export default function MiniRow({
    label,
    value,
}: Readonly<{ label: string; value: string }>) {
    return (
        <div className="flex justify-between border-b border-border py-1 text-[0.6875rem] last:border-b-0">
            <span className="text-foreground">{label}</span>
            <b className="tabular-nums text-foreground">{value}</b>
        </div>
    );
}
