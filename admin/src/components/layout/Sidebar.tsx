import {
  BarChart3,
  Bike,
  ChevronDown,
  Gem,
  LayoutDashboard,
  LogOut,
  SearchCheck,
  Settings,
  TrendingUp,
  X,
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
  { to: '/aptos-inspector', href: '/aptos/admin/inspector', label: 'Aptos Inspector', icon: SearchCheck },
]

function NavItem({
  to,
  label,
  icon: Icon,
  end,
  onItemClick,
}: {
  to: string
  label: string
  icon: React.ComponentType<{ className?: string }>
  end?: boolean
  onItemClick?: () => void
}) {
  return (
    <NavLink
      to={to}
      end={end}
      onClick={onItemClick}
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

export function Sidebar({
  onLogout,
  mobileOpen,
  onClose,
}: {
  onLogout: () => void
  mobileOpen?: boolean
  onClose?: () => void
}) {
  const content = (
    <div className="flex h-full flex-col justify-between">
      <div>
        <div className="flex items-center justify-between">
          <Logo />
          {onClose && (
            <button
              type="button"
              onClick={onClose}
              className="rounded-lg p-1 text-gray-500 hover:bg-gray-100 md:hidden"
              aria-label="Close sidebar"
            >
              <X className="h-5 w-5" />
            </button>
          )}
        </div>
        <nav className="mt-8 flex flex-col gap-1">
          {navItems.map((item) => (
            <NavItem key={item.to} {...item} onItemClick={onClose} />
          ))}
        </nav>
      </div>

      <div className="mt-auto flex flex-col gap-1 border-t border-sendra-border pt-4">
        <NavLink
          to="/settings"
          onClick={onClose}
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
            if (onClose) onClose()
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
    </div>
  )

  return (
    <>
      {/* Desktop sidebar */}
      <aside className="hidden h-screen w-56 shrink-0 sticky top-0 border-r border-sendra-border bg-white px-4 py-6 md:flex">
        {content}
      </aside>

      {/* Mobile drawer overlay & slide-out sidebar */}
      {mobileOpen && (
        <div className="fixed inset-0 z-50 flex md:hidden">
          <div
            className="fixed inset-0 bg-black/50 backdrop-blur-xs transition-opacity"
            onClick={onClose}
          />
          <aside className="relative z-50 flex h-full w-64 flex-col border-r border-sendra-border bg-white px-4 py-6 shadow-2xl">
            {content}
          </aside>
        </div>
      )}
    </>
  )
}
