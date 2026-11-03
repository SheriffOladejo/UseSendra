import { Bell, Menu } from 'lucide-react'

export function Header({ onOpenMenu }: { onOpenMenu?: () => void }) {
  return (
    <header className="flex items-center justify-between pb-6">
      <div className="flex items-center gap-3">
        {onOpenMenu && (
          <button
            type="button"
            onClick={onOpenMenu}
            className="rounded-xl border border-sendra-border bg-white p-2 text-gray-700 hover:bg-gray-50 md:hidden"
            aria-label="Open menu"
          >
            <Menu className="h-5 w-5" />
          </button>
        )}
        <p className="text-base font-medium text-gray-800">Welcome, Admin 👋</p>
      </div>
      <div className="flex items-center gap-4">
        <button
          type="button"
          className="rounded-full p-2 text-gray-600 hover:bg-white"
          aria-label="Notifications"
        >
          <Bell className="h-5 w-5" />
        </button>
        <div
          className="flex h-10 w-10 items-center justify-center rounded-full bg-sendra-primary text-sm font-semibold text-white shadow-sm"
          aria-hidden
        >
          SA
        </div>
      </div>
    </header>
  )
}
