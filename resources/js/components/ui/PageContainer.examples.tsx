import PageContainer from '@/components/ui/PageContainer';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'PageContainer',
    description:
        "Every page's column: full width on a phone, 760px from 900px, 1040px from 1280px, with the shared entrance.",
    usage: `<PageContainer>
    {sections}
</PageContainer>`,
    states: [
        {
            name: 'default',
            render: () => (
                <PageContainer>
                    <div className="rounded-sm bg-secondary pad-panel text-meta">
                        page content
                    </div>
                </PageContainer>
            ),
        },
    ],
} satisfies CatalogueEntry;
