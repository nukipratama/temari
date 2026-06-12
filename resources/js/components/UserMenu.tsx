import { router } from "@inertiajs/react";
import { Icon } from "@iconify/react";
import { useCallback, useRef, useState } from "react";
import UserAvatar from "@/components/UserAvatar";
import { useDismissable } from "@/hooks/useDismissable";

/**
 * Avatar button that opens a dropdown with the signed-in name and a logout
 * action. Shared by the desktop `TopNav` and the mobile `MobileTopBar` so
 * logout is one tap from every page on both layouts.
 */
export default function UserMenu({
  name,
  avatarUrl,
}: Readonly<{ name: string; avatarUrl: string | null }>) {
  const [open, setOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  const close = useCallback(() => setOpen(false), []);
  useDismissable(open, containerRef, close);

  function handleLogout() {
    setOpen(false);
    router.post("/logout");
  }

  return (
    <div ref={containerRef} className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={`Buka menu ${name}`}
        className="flex h-9 w-9 items-center justify-center rounded-full ring-2 ring-cream-deep transition hover:ring-leaf focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2 focus-visible:ring-offset-cream"
      >
        <UserAvatar name={name} avatarUrl={avatarUrl} size="md" />
      </button>
      {open && (
        <div
          role="menu"
          className="absolute right-0 top-[calc(100%+10px)] z-40 w-52 overflow-hidden rounded-2xl border border-cream-deep bg-cream shadow-lg"
        >
          <div className="border-b border-cream-deep px-4 py-3">
            <div className="text-label-micro font-semibold text-ink-3">
              Masuk sebagai
            </div>
            <div className="mt-0.5 truncate font-sans text-sm font-medium text-ink">
              {name}
            </div>
          </div>
          <button
            type="button"
            role="menuitem"
            onClick={handleLogout}
            className="flex w-full items-center gap-2.5 px-4 py-2.5 text-left font-sans text-sm text-ink transition hover:bg-cream-deep"
          >
            <Icon
              icon="mdi:logout"
              width={16}
              height={16}
              aria-hidden
              className="text-ink-3"
            />
            Keluar
          </button>
        </div>
      )}
    </div>
  );
}
