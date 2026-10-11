import BackLink from '@/components/ui/BackLink';
import Eyebrow from '@/components/ui/Eyebrow';

export default function RaceHeading() {
    return (
        <>
            <BackLink href="/plan" className="mb-3">
                plan
            </BackLink>
            <Eyebrow token="hero" tone="ink-2">
                Race
            </Eyebrow>
            <h1 className="mt-2 font-serif text-quote-lg text-foreground italic">
                your <em className="text-horizon-ink">race.</em>
            </h1>
        </>
    );
}
