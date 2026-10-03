import Skeleton, {
    SkeletonChart,
    SkeletonProse,
    SkeletonRows,
    SkeletonStats,
} from '@/components/ui/Skeleton';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'Skeleton',
    description:
        'Loading placeholders. Skeleton is one bar sized by className; the named shapes mock prose, a stat rail, a chart and rows.',
    usage: `<SkeletonProse />`,
    states: [
        {
            name: 'bar',
            render: () => <Skeleton className="h-4 w-40" />,
        },
        { name: 'prose', render: () => <SkeletonProse /> },
        { name: 'stats', render: () => <SkeletonStats count={3} /> },
        {
            name: 'chart',
            render: () => <SkeletonChart className="h-[120px]" />,
        },
        { name: 'rows', render: () => <SkeletonRows count={2} /> },
    ],
} satisfies CatalogueEntry;
