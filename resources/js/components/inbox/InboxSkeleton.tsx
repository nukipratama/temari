import Eyebrow from '@/components/ui/Eyebrow';
import PageContainer from '@/components/ui/PageContainer';
import Skeleton, { SkeletonRows } from '@/components/ui/Skeleton';

export default function InboxSkeleton() {
    return (
        <PageContainer reveal={false}>
            <Eyebrow token="hero" tone="ink-2" className="mb-3.5">
                Inbox
            </Eyebrow>
            <Skeleton className="h-9 w-3/5" />
            <SkeletonRows count={5} className="mt-8" />
        </PageContainer>
    );
}
