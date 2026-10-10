import AiOutageBanner from '@/components/AiOutageBanner';
import PlanHeading from '@/components/plan/PlanHeading';
import PageContainer from '@/components/ui/PageContainer';
import Skeleton, {
    SkeletonRows,
    SkeletonStats,
} from '@/components/ui/Skeleton';

export function PlanWeeksSkeleton() {
    return (
        <div className="mt-6 flex flex-col gap-4">
            <SkeletonStats />
            <SkeletonRows count={4} />
        </div>
    );
}

export default function PlanSkeleton() {
    return (
        <>
            <AiOutageBanner />
            <PageContainer reveal={false}>
                <PlanHeading
                    action={
                        <Skeleton className="h-8 w-9.5 flex-none rounded-full" />
                    }
                />
                <Skeleton className="mt-1 mb-4 h-4 w-64 max-w-full" />
                <PlanWeeksSkeleton />
            </PageContainer>
        </>
    );
}
