import Eyebrow from '@/components/ui/Eyebrow';
import LaneStack from '@/components/ui/LaneStack';
import PageContainer from '@/components/ui/PageContainer';
import Skeleton, { SkeletonRows } from '@/components/ui/Skeleton';

const SECTIONS = ['appearance', 'notifications', 'account'];

export default function SettingsSkeleton() {
    return (
        <PageContainer reveal={false}>
            <div className="mb-8">
                <Eyebrow token="hero" tone="ink-2" className="mb-3.5">
                    Settings
                </Eyebrow>
                <Skeleton className="h-9 w-2/5" />
                <Skeleton className="mt-2 h-9 w-3/5" />
            </div>
            <LaneStack>
                {SECTIONS.map((section) => (
                    <div key={section}>
                        <Skeleton className="h-4 w-28" />
                        <SkeletonRows count={2} className="mt-3" />
                    </div>
                ))}
            </LaneStack>
        </PageContainer>
    );
}
