import LaneStack from '@/components/ui/LaneStack';
import PageContainer from '@/components/ui/PageContainer';
import Skeleton, {
    SkeletonProse,
    SkeletonRows,
} from '@/components/ui/Skeleton';

const WEEK_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

function SessionSkeleton() {
    return (
        <div>
            <Skeleton className="h-4 w-14" />
            <Skeleton className="mt-2 h-8 w-36" />
            <Skeleton className="mt-1 h-5 w-28" />
            <Skeleton className="mt-1.5 h-5 w-4/5" />
            <SkeletonProse className="mt-3" />
            <Skeleton className="mt-1 h-4 w-24" />
        </div>
    );
}

function WeekPlanSkeleton() {
    return (
        <div>
            <div className="mb-3.5 flex items-center justify-between gap-2">
                <Skeleton className="h-4 w-32" />
                <Skeleton className="h-6 w-14 rounded-full" />
            </div>
            <Skeleton className="h-8 w-36" />
            <div className="mt-3 mb-3.5 grid grid-cols-2 gap-2">
                <Skeleton className="h-19 rounded-sm" />
                <Skeleton className="h-19 rounded-sm" />
            </div>
            <div className="mb-3.5 grid grid-cols-7 gap-1">
                {WEEK_DAYS.map((day) => (
                    <Skeleton key={day} className="h-20 rounded-sm" />
                ))}
            </div>
            <Skeleton className="h-9 w-full rounded-lg" />
        </div>
    );
}

function PastYouSkeleton() {
    return (
        <div>
            <Skeleton className="h-4 w-48" />
            <Skeleton className="mt-2 h-5.5 w-3/4" />
            <Skeleton className="mt-2 h-5 w-40" />
            <SkeletonRows count={4} className="mt-4" />
        </div>
    );
}

export default function TodaySkeleton() {
    return (
        <PageContainer reveal={false}>
            <h1 className="sr-only">today</h1>
            <LaneStack>
                <SessionSkeleton />
                <WeekPlanSkeleton />
                <PastYouSkeleton />
            </LaneStack>
        </PageContainer>
    );
}
