import { cn } from '@/lib/cn';

import { wordDiff, type DiffOp } from './wordDiff';

const OP_CLASS: Record<DiffOp, string> = {
    same: 'text-text-2',
    added: 'bg-leaf/18 text-leaf-ink',
    removed: 'bg-ember/18 text-ember-ink line-through',
};

/** The previous narration against the current one, word by word. */
export default function VersionDiff({
    before,
    after,
}: Readonly<{ before: string; after: string }>) {
    const tokens = wordDiff(before, after);

    return (
        <div className="mt-2 rounded-xl border border-border bg-muted p-3">
            <p className="text-label-micro font-semibold uppercase text-text-3">
                previous vs current
            </p>
            <p className="mt-2 text-sm leading-relaxed">
                {tokens.map((token) => (
                    <span
                        key={token.id}
                        className={cn('rounded-sm', OP_CLASS[token.op])}
                    >
                        {token.text}{' '}
                    </span>
                ))}
            </p>
        </div>
    );
}
