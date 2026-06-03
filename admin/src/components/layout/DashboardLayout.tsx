import { Outlet } from 'react-router-dom'
import { Header } from './Header'
import { Sidebar } from './Sidebar'

export function DashboardLayout({ onLogout }: { onLogout: () => void }) {
  return (
    <div className="flex min-h-screen bg-sendra-surface">
      <Sidebar onLogout={onLogout} />
      <main className="flex-1 overflow-y-auto p-8">
        <Header />
        <Outlet />
      </main>
    </div>
  )
}
