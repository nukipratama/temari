import AiOutageBanner from '@/components/AiOutageBanner';
import Eyebrow from '@/components/ui/Eyebrow';
import LaneStack from '@/components/ui/LaneStack';
import PageContainer from '@/components/ui/PageContainer';
import Skeleton, {
    SkeletonChart,
    SkeletonProse,
    SkeletonStats,
} from '@/components/ui/Skeleton';

export default function RunShowSkeleton() {
    return (
        <>
            <AiOutageBanner />
            <PageContainer reveal={false}>
                <Eyebrow token="hero" tone="ink-3">
                    Activity
                </Eyebrow>
                <LaneStack className="mt-6">
                    <div>
                        <Skeleton className="h-8 w-3/4" />
                        <Skeleton className="mt-2 h-5 w-40" />
                        <SkeletonStats count={4} className="mt-5" />
                    </div>
                    <SkeletonProse />
                    <SkeletonChart />
                </LaneStack>
            </PageContainer>
        </>
    );
}
