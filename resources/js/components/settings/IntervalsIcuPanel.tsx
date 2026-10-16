import { router, useForm } from '@inertiajs/react';
import { LoaderCircle, Plug, Watch } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import DemoBlockedModal from '@/components/DemoBlockedModal';
import Eyebrow from '@/components/ui/Eyebrow';
import FieldError from '@/components/ui/FieldError';
import { Icon } from '@/components/ui/Icon';
import PillButton from '@/components/ui/PillButton';
import SettingsDisconnectLink from '@/components/ui/SettingsDisconnectLink';
import SettingsRow from '@/components/ui/SettingsRow';
import { useDemoGuard } from '@/hooks/useDemoGuard';
import { cn } from '@/lib/cn';

export interface IntervalsIcuPayload {
    connected: boolean;
    athlete_id: string | null;
    broken: boolean;
}

export const INTERVALS_ICU_DEMO_BLOCKED = {
    title: 'intervals.icu stays unplugged in the demo',
    body: 'this is the shared demo, so it has no watch to send runs to. sign in with your own Strava to connect intervals.icu.',
} as const;

const INTERVALS_ICU_SETTINGS_URL = 'https://intervals.icu/settings';

const INPUT =
    'focus-ring mt-1 block w-full rounded-lg border bg-muted px-2.5 py-2 font-mono text-sm font-bold text-foreground motion-safe:transition-colors motion-safe:duration-150 focus:border-horizon';

function description(intervalsIcu: IntervalsIcuPayload): string {
    if (!intervalsIcu.connected) {
        return 'send your next run to your watch.';
    }
    if (intervalsIcu.broken) {
        return 'your key stopped working · reconnect to keep sending runs.';
    }
    return `connected · athlete ${intervalsIcu.athlete_id}`;
}

export default function IntervalsIcuPanel({
    intervalsIcu,
}: Readonly<{ intervalsIcu: IntervalsIcuPayload }>) {
    const [open, setOpen] = useState(false);
    const { open: demoOpen, setOpen: setDemoOpen, guard } = useDemoGuard();
    const healthy = intervalsIcu.connected && !intervalsIcu.broken;

    return (
        <>
            {healthy ? (
                <SettingsRow
                    icon={Watch}
                    label="intervals.icu"
                    description={description(intervalsIcu)}
                    control={<span aria-hidden />}
                />
            ) : (
                <SettingsRow
                    icon={Watch}
                    label="intervals.icu"
                    description={description(intervalsIcu)}
                    expanded={open}
                    onClick={() => guard(() => setOpen((v) => !v))}
                />
            )}

            {intervalsIcu.connected && (
                <div className="-mt-1 pl-11">
                    <SettingsDisconnectLink
                        onClick={() =>
                            guard(() =>
                                router.delete('/settings/intervals-icu', {
                                    preserveScroll: true,
                                }),
                            )
                        }
                    />
                </div>
            )}

            {!healthy && open && (
                <ConnectForm
                    athleteId={intervalsIcu.athlete_id ?? ''}
                    onConnected={() => setOpen(false)}
                />
            )}

            <DemoBlockedModal
                open={demoOpen}
                onClose={() => setDemoOpen(false)}
                title={INTERVALS_ICU_DEMO_BLOCKED.title}
                body={INTERVALS_ICU_DEMO_BLOCKED.body}
            />
        </>
    );
}

function ConnectForm({
    athleteId,
    onConnected,
}: Readonly<{ athleteId: string; onConnected: () => void }>) {
    const { data, setData, post, processing, errors, reset } = useForm({
        athlete_id: athleteId,
        api_key: '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        post('/settings/intervals-icu', {
            preserveScroll: true,
            onSuccess: onConnected,
            onFinish: () => reset('api_key'),
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-3 pt-2 pb-2 pl-11">
            <p className="font-sans text-xs leading-relaxed text-text-2">
                temari puts your planned runs on your intervals.icu calendar,
                and intervals.icu passes the next one to your watch app. tested
                on Huawei; should work with any watch intervals.icu supports.
            </p>
            <div className="grid gap-3 min-[900px]:grid-cols-2">
                <label className="block">
                    <Eyebrow
                        as="span"
                        token="micro"
                        tone="ink-3"
                        className="block"
                    >
                        athlete ID
                    </Eyebrow>
                    <input
                        type="text"
                        name="athlete_id"
                        autoComplete="off"
                        spellCheck={false}
                        placeholder="i123456"
                        value={data.athlete_id}
                        onChange={(e) => setData('athlete_id', e.target.value)}
                        aria-invalid={errors.athlete_id ? true : undefined}
                        className={cn(
                            INPUT,
                            errors.athlete_id
                                ? 'border-ember-deep'
                                : 'border-border-strong',
                        )}
                    />
                    <FieldError message={errors.athlete_id} />
                </label>
                <label className="block">
                    <Eyebrow
                        as="span"
                        token="micro"
                        tone="ink-3"
                        className="block"
                    >
                        API key
                    </Eyebrow>
                    <input
                        type="password"
                        name="api_key"
                        autoComplete="off"
                        placeholder="paste your key"
                        value={data.api_key}
                        onChange={(e) => setData('api_key', e.target.value)}
                        aria-invalid={errors.api_key ? true : undefined}
                        className={cn(
                            INPUT,
                            errors.api_key
                                ? 'border-ember-deep'
                                : 'border-border-strong',
                        )}
                    />
                    <FieldError message={errors.api_key} />
                </label>
            </div>
            <p className="font-sans text-xs text-text-3">
                find both in intervals.icu under Settings → Developer settings.{' '}
                <a
                    href={INTERVALS_ICU_SETTINGS_URL}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="underline underline-offset-2"
                >
                    open intervals.icu
                </a>
            </p>
            <PillButton
                type="submit"
                tone="outline"
                disabled={processing}
                className="w-full justify-center disabled:cursor-not-allowed disabled:opacity-60 min-[900px]:w-auto min-[900px]:self-start"
            >
                <Icon
                    icon={processing ? LoaderCircle : Plug}
                    width={14}
                    height={14}
                    className={processing ? 'animate-spin' : undefined}
                    aria-hidden
                />
                {processing ? 'checking…' : 'connect'}
            </PillButton>
        </form>
    );
}
