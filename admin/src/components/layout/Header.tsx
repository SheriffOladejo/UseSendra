import { Bell } from 'lucide-react'

export function Header() {
  return (
    <header className="flex items-center justify-between pb-6">
      <p className="text-base font-medium text-gray-800">Welcome Victor 👋</p>
      <div className="flex items-center gap-4">
        <button
          type="button"
          className="rounded-full p-2 text-gray-600 hover:bg-white"
          aria-label="Notifications"
        >
          <Bell className="h-5 w-5" />
        </button>
        <div
          className="flex h-10 w-10 items-center justify-center rounded-full bg-gray-800 text-sm font-semibold text-white"
          aria-hidden
        >
          OV
        </div>
      </div>
    </header>
  )
}
