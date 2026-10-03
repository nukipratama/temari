import { useId, useLayoutEffect, useState } from 'react';

import { CATALOGUE } from '@/components/catalogue/entries';
import EntrySection from '@/components/catalogue/EntrySection';
import EmptyPanel from '@/components/ui/EmptyPanel';
import Eyebrow from '@/components/ui/Eyebrow';
import { type CatalogueItem, groupItems, matchesQuery } from '@/lib/catalogue';
import { cn } from '@/lib/cn';
import { inputVariants, laneStack } from '@/lib/variants';

/** Holds the document on the light ground so each example's light frame is truly light. */
function usePinnedLightGround(): void {
    useLayoutEffect(() => {
        const root = document.documentElement;
        const previous = root.dataset.theme;
        root.dataset.theme = 'light';

        return () => {
            if (previous === undefined) {
                delete root.dataset.theme;
            } else {
                root.dataset.theme = previous;
            }
        };
    }, []);
}

export default function Catalogue({
    items = CATALOGUE,
}: Readonly<{ items?: readonly CatalogueItem[] }>) {
    usePinnedLightGround();
    const searchId = useId();
    const [query, setQuery] = useState('');
    const shown = items.filter((item) => matchesQuery(item, query));

    return (
        <div className="lg:grid lg:grid-cols-[13rem_minmax(0,1fr)] lg:gap-10">
            <aside className="lg:sticky lg:top-6 lg:max-h-[calc(100svh-3rem)] lg:self-start lg:overflow-y-auto lg:pb-6">
                <label
                    htmlFor={searchId}
                    className="text-label-micro text-text-3"
                >
                    search
                </label>
                <input
                    id={searchId}
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="name, folder or purpose"
                    className={cn(inputVariants({ size: 'sm' }), 'mt-1.5')}
                />
                <p className="mt-2 text-meta">
                    {shown.length} of {items.length} components
                </p>
                <nav aria-label="Components" className="mt-5 hidden lg:block">
                    {groupItems(shown).map(([group, members]) => (
                        <div key={group} className="mb-5">
                            <Eyebrow token="micro" tone="ink-3" as="h2">
                                {group}
                            </Eyebrow>
                            <ul className="mt-1.5 flex flex-col gap-1">
                                {members.map((member) => (
                                    <li key={member.id}>
                                        <a
                                            href={`#${member.id}`}
                                            className="focus-ring rounded-xs font-sans text-sm text-text-2 transition hover:text-foreground"
                                        >
                                            {member.name}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </nav>
            </aside>

            <div className="mt-8 min-w-0 lg:mt-0">
                {shown.length === 0 ? (
                    <EmptyPanel
                        title="nothing matches"
                        body={`no component name, folder or description contains "${query.trim()}".`}
                    />
                ) : (
                    <div className={laneStack}>
                        {shown.map((item) => (
                            <EntrySection key={item.id} item={item} />
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
