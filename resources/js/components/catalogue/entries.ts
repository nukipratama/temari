import { type CatalogueEntry, catalogueItems } from '@/lib/catalogue';

/** Every co-located `*.examples.tsx` under components/, bundled only into the devtools design page. */
export const CATALOGUE = catalogueItems(
    import.meta.glob<{ default: CatalogueEntry }>('../**/*.examples.tsx', {
        eager: true,
    }),
);
