import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { type CatalogueEntry } from '@/lib/catalogue';

function Demo({ open }: Readonly<{ open: boolean }>) {
    return (
        <Collapsible defaultOpen={open}>
            <CollapsibleTrigger className="focus-ring rounded-xs text-label-small text-text-2 hover:text-foreground">
                past weeks
            </CollapsibleTrigger>
            <CollapsibleContent className="mt-2 text-sm text-text-2">
                week 37 · 28 km · 4 runs
            </CollapsibleContent>
        </Collapsible>
    );
}

export default {
    name: 'Collapsible',
    description:
        'Base UI disclosure: a trigger that shows and hides a panel. Unstyled; the caller owns the look.',
    usage: `<Collapsible>
    <CollapsibleTrigger>past weeks</CollapsibleTrigger>
    <CollapsibleContent>{weeks}</CollapsibleContent>
</Collapsible>`,
    states: [
        { name: 'closed', render: () => <Demo open={false} /> },
        { name: 'open', render: () => <Demo open /> },
    ],
} satisfies CatalogueEntry;
