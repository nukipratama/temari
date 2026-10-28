{{-- The app's chrome in skeleton form, painted from the HTML alone so a cold
     launch hands the iOS launch image to something app-shaped instead of an
     empty ground. Hidden by app.css the moment React renders into #app. --}}
<div id="boot-shell" aria-hidden="true" class="pointer-events-none fixed inset-0 z-40 bg-background pl-[env(safe-area-inset-left)] pr-[env(safe-area-inset-right)]">
    <div class="mx-auto flex w-full items-center justify-between gap-3 px-4 pt-[max(1rem,env(safe-area-inset-top))] min-[900px]:max-w-column min-[900px]:px-6 min-[1280px]:max-w-column-wide">
        <span class="skeleton h-9 w-28 rounded-full"></span>
        <span class="flex items-center gap-2">
            <span class="skeleton size-10 rounded-full"></span>
            <span class="skeleton size-11 rounded-full"></span>
        </span>
    </div>
    <div class="mx-auto mt-8 flex w-full flex-col gap-4 px-4 min-[900px]:max-w-column min-[900px]:px-6 min-[1280px]:max-w-column-wide">
        <span class="skeleton h-3 w-16 rounded-sm"></span>
        <span class="skeleton h-10 w-44 rounded-md"></span>
        <span class="skeleton h-24 w-full rounded-xl"></span>
        <span class="skeleton h-44 w-full rounded-xl"></span>
    </div>
    <div class="fixed inset-x-0 bottom-[max(0.875rem,calc(env(safe-area-inset-bottom)+0.25rem))] pl-[max(0.875rem,env(safe-area-inset-left))] pr-[max(0.875rem,env(safe-area-inset-right))]">
        <div class="mx-auto h-14 rounded-full border border-foreground/20 bg-card/60 min-[900px]:max-w-column min-[1280px]:max-w-column-wide"></div>
    </div>
</div>
