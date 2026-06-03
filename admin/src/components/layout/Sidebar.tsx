import {
  BarChart3,
  Bike,
  ChevronDown,
  Gem,
  LayoutDashboard,
  LogOut,
  Settings,
  TrendingUp,
} from 'lucide-react'
import { NavLink } from 'react-router-dom'
import { clearAdminSecret } from '../../lib/api'
import { Logo } from './Logo'

const navItems = [
  { to: '/', label: 'Overview', icon: LayoutDashboard, end: true },
  { to: '/customers', label: 'Customers', icon: TrendingUp },
  { to: '/riders', label: 'Riders', icon: Bike },
  { to: '/orders', label: 'Orders', icon: Gem },
  { to: '/analytics', label: 'Analytics', icon: BarChart3 },
]

function NavItem({
  to,
  label,
  icon: Icon,
  end,
}: {
  to: string
  label: string
  icon: React.ComponentType<{ className?: string }>
  end?: boolean
}) {
  return (
    <NavLink
      to={to}
      end={end}
      className={({ isActive }) =>
        `flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors ${
          isActive
            ? 'bg-sendra-mint text-sendra-primary'
            : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
        }`
      }
    >
      {({ isActive }) => (
        <>
          <Icon className={`h-5 w-5 shrink-0 ${isActive ? 'text-sendra-primary' : ''}`} />
          {label}
        </>
      )}
    </NavLink>
  )
}

export function Sidebar({ onLogout }: { onLogout: () => void }) {
  return (
    <aside className="flex h-screen w-56 shrink-0 flex-col border-r border-sendra-border bg-white px-4 py-6">
      <Logo />
      <nav className="mt-8 flex flex-1 flex-col gap-1">
        {navItems.map((item) => (
          <NavItem key={item.to} {...item} />
        ))}
      </nav>
      <div className="mt-auto flex flex-col gap-1 border-t border-sendra-border pt-4">
        <NavLink
          to="/settings"
          className={({ isActive }) =>
            `flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium ${
              isActive ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-100'
            }`
          }
        >
          <Settings className="h-5 w-5" />
          Settings
        </NavLink>
        <button
          type="button"
          onClick={() => {
            clearAdminSecret()
            onLogout()
          }}
          className="flex w-full items-center justify-between rounded-xl px-3 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-100"
        >
          <span className="flex items-center gap-3">
            <LogOut className="h-5 w-5" />
            Logout
          </span>
          <ChevronDown className="h-4 w-4" />
        </button>
      </div>
    </aside>
  )
}
