import { useState } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { DashboardLayout } from './components/layout/DashboardLayout'
import { getAdminSecret } from './lib/api'
import { AnalyticsPage } from './pages/AnalyticsPage'
import { CustomersPage } from './pages/CustomersPage'
import { LoginPage } from './pages/LoginPage'
import { OverviewPage } from './pages/OverviewPage'
import { SettingsPage } from './pages/SettingsPage'
import { SupportTicketsPage } from './pages/SupportTicketsPage'
import { MonitoringPage } from './pages/MonitoringPage'
import { TransactionsPage } from './pages/TransactionsPage'
import { TxInspectorPage } from './pages/TxInspectorPage'

export default function App() {
  const [authed, setAuthed] = useState(() => !!getAdminSecret())

  if (!authed) {
    return <LoginPage onSuccess={() => setAuthed(true)} />
  }

  return (
    <BrowserRouter basename="/aptos/admin">
      <Routes>
        <Route element={<DashboardLayout onLogout={() => setAuthed(false)} />}>
          <Route index element={<OverviewPage />} />
          <Route path="customers" element={<CustomersPage />} />
          <Route path="transactions" element={<TransactionsPage />} />
          <Route path="monitoring" element={<MonitoringPage />} />
          <Route path="inspector" element={<TxInspectorPage />} />
          <Route path="analytics" element={<AnalyticsPage />} />
          <Route path="support" element={<SupportTicketsPage />} />
          <Route path="settings" element={<SettingsPage />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </BrowserRouter>
  )
}
