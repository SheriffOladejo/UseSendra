import { useState } from 'react'
import { Outlet } from 'react-router-dom'
import { Header } from './Header'
import { Sidebar } from './Sidebar'

export function DashboardLayout({ onLogout }: { onLogout: () => void }) {
  const [mobileOpen, setMobileOpen] = useState(false)

  return (
    <div className="flex min-h-screen bg-sendra-surface">
      <Sidebar
        onLogout={onLogout}
        mobileOpen={mobileOpen}
        onClose={() => setMobileOpen(false)}
      />
      <main className="flex-1 min-w-0 overflow-y-auto p-4 sm:p-6 md:p-8">
        <Header onOpenMenu={() => setMobileOpen(true)} />
        <Outlet />
      </main>
    </div>
  )
}
