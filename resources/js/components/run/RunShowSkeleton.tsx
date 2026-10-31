import AiOutageBanner from '@/components/AiOutageBanner';
import Eyebrow from '@/components/ui/Eyebrow';
import LaneStack from '@/components/ui/LaneStack';
import PageContainer from '@/components/ui/PageContainer';
import Skeleton, {
    SkeletonChart,
    SkeletonProse,
    SkeletonStats,
} from '@/components/ui/Skeleton';

/** Draws the effort picker's block unless the run is known to have a saved effort. */
export default function RunShowSkeleton({
    effortSaved = null,
}: Readonly<{ effortSaved?: boolean | null }>) {
    return (
        <>
            <AiOutageBanner />
            <PageContainer reveal={false}>
                {effortSaved !== true && (
                    <section className="mb-6">
                        <Skeleton className="h-[196px] rounded-panel" />
                    </section>
                )}
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
