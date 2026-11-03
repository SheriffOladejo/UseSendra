const API_BASE = import.meta.env.VITE_API_BASE ?? '/aptos/api'
const STORAGE_KEY = 'sendra_aptos_admin_secret'

export function getAdminSecret(): string | null {
  return sessionStorage.getItem(STORAGE_KEY)
}

export function setAdminSecret(secret: string): void {
  sessionStorage.setItem(STORAGE_KEY, secret)
}

export function clearAdminSecret(): void {
  sessionStorage.removeItem(STORAGE_KEY)
}

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const secret = getAdminSecret()
  if (!secret) {
    throw new Error('Not authenticated')
  }

  const res = await fetch(`${API_BASE}${path}`, {
    ...init,
    headers: {
      'Content-Type': 'application/json',
      'X-Admin-Secret': secret,
      ...(init?.headers ?? {}),
    },
  })

  if (res.status === 401) {
    clearAdminSecret()
    window.location.reload()
    throw new Error('Unauthorized')
  }

  const data = await res.json()
  if (!res.ok) {
    throw new Error(data.error ?? `Request failed (${res.status})`)
  }

  return data as T
}

export type OverviewResponse = {
  stats: {
    totalVolumeUsd: number
    inflowUsd: number
    outflowUsd: number
    inflowSuccessRate: number
    outflowSuccessRate: number
    revenueUsd: number
    totalTransactions: number
    vouchersClaimed: number
    vouchersTotal: number
    voucherSuccessRate: number
  }
  recentActivities: Array<{
    kind: string
    title: string
    meta: string
    time: string
  }>
}

export type CustomerKycResponse = {
  id: number
  name: string
  phone: string
  kycStatus: string
  verifiedAt: string | null
  tiers: Array<{
    tier: number
    label: string
    fields: Array<{ label: string; value: string | null; proof?: boolean }>
  }>
}

export type CustomersResponse = {
  stats: {
    totalCustomers: number
    activeCustomers: number
    kycVerified: number
    kycTotal: number
  }
  customers: Array<{
    id: number
    name: string
    number: string
    wallet: string
    walletShort: string
    kycStatus: string
  }>
}

export type TransactionsResponse = {
  stats: {
    totalTransactions: number
    inCount: number
    outCount: number
    inflowSuccessRate: number
    outflowSuccessRate: number
  }
  pagination: { page: number; pageSize: number; total: number; totalPages: number }
  transactions: Array<{
    id: string
    timestamp: string
    number: string
    type: string
    typeKey: string
    amount: string
    currency: string
    status: string
    from: string
    to: string
    tx: string | null
  }>
}

export type AnalyticsResponse = {
  stats: {
    newUsersToday: number
    newUsersTrendPct: number
    activationRate: number
    activationTrendPct: number
    dailyActiveUsers: number
    dauTrendPct: number
  }
  usageTrends: Array<{ day: number; activeUsers: number }>
  activityDistribution: Array<{ label: string; count: number }>
}

export type SupportTicketsResponse = {
  stats: { open: number; resolved: number; total: number }
  pagination: { page: number; pageSize: number; total: number; totalPages: number }
  tickets: Array<{
    id: string
    user: string
    subject: string
    status: string
    createdAt: string
  }>
}

export type InspectorChain = 'aptos' | 'stellar' | 'arc' | 'solana' | 'polygon' | string

export type InspectorResponse = {
  query: string
  resolvedChain?: InspectorChain | null
  user: {
    id: number
    name: string
    phone: string
    wallet: string
    walletShort: string
    wallets?: {
      aptos?: string
      aptosShort?: string
      stellar?: string
      stellarShort?: string
      arc?: string
      arcShort?: string
    }
    kycTier: string
    createdAt: string
  } | null
  offrampOutcome: {
    id: number
    centiiv_order_id: string
    user_phone: string
    offramp_path: string
    target_ngn: string
    quoted_ngn_at_pin: string
    fulfillment_ngn: string
    usdt_debit: string
    stellar_deposit_usdc: string
    centiiv_order_usdc: string
    ngn_shortfall: string
    created_at: string
    centiiv_rate?: string
    effective_rate?: string
    fees_charged?: string
    shortfall?: string
  } | null
  pendingOrder: Record<string, any> | null
  pendingNative?: {
    centiiv_order_id?: string
    tx_hash?: string
    tx_type?: string
    chain?: string
  } | null
  centiivOrderId: string | null
  centiiv: Record<string, any> | null
  oneClickAddress: string | null
  oneClick: Record<string, any> | null
  history: Array<{
    id: string
    type: string
    direction?: string
    amount: string
    currency: string
    chain?: InspectorChain
    chainLabel?: string
    status: string
    txHash: string
    explorerUrl?: string
    createdAt: string
  }>
}

export type MonitorParty = {
  account_number?: string | null
  account_name?: string | null
  bank_name?: string | null
  fiat_amount?: number | null
  fiat_currency?: string | null
  crypto_amount?: number | null
  crypto_currency?: string | null
  conversion_rate?: number | null
  conversion_rate_label?: string | null
  receive_display?: string | null
}

export type MonitorItem = {
  id: number
  phone: string
  activityType: string
  status: string
  amount: string
  currency: string
  usdEquivalent: number
  riskScore: number
  scumlReportable: boolean
  scumlReportedAt: string | null
  emailSentAt: string | null
  occurredAt: string
  reasons: string[]
  details: {
    offramp?: MonitorParty | null
    onramp?: MonitorParty | null
    onchain?: {
      network?: string | null
      source_address?: string | null
      destination_address?: string | null
      amount?: number | null
      currency?: string | null
      tx_hash?: string | null
      explorer_url?: string | null
    } | null
  }
  kyc: {
    tier: number | null
    fullName: string | null
    ninName: string | null
    bvnName: string | null
    ninPhone: string | null
    bvnPhone: string | null
    ninMask: string | null
    bvnMask: string | null
    address: string | null
  } | null
}

export type ActivityHold = {
  phone: string
  code: string
  reason: string
  startedAt: string
  endsAt: string
}

export type MonitoringResponse = {
  stats: { total: number; attention: number; scuml: number; scumlOpen: number }
  recipients: string[]
  scannedEmailsSent: number
  holds: ActivityHold[]
  pagination: { page: number; pageSize: number; total: number; totalPages: number }
  items: MonitorItem[]
}

export type SystemStatusService = {
  key: string
  label: string
  ok: boolean | null
  skipped: boolean
  ms: number | null
  http: number | null
  error: string | null
  note: string | null
  informational: boolean
  correlationId: string | null
}

export type SystemStatusResponse = {
  ok: boolean
  checked_at: string | null
  age_seconds: number | null
  stale: boolean
  gate_enabled: boolean
  services: SystemStatusService[]
  summary: string
}

export const api = {
  overview: () => request<OverviewResponse>('/overview'),
  customerKyc: (id: number) => request<CustomerKycResponse>(`/customer-kyc?id=${id}`),
  openCustomerKycProof: async (id: number) => {
    const secret = getAdminSecret()
    if (!secret) throw new Error('Not authenticated')
    const res = await fetch(`${API_BASE}/customer-kyc-proof?id=${id}`, {
      headers: { 'X-Admin-Secret': secret },
    })
    if (!res.ok) throw new Error('Could not open the proof document')
    const blob = await res.blob()
    const url = URL.createObjectURL(blob)
    window.open(url, '_blank', 'noopener')
  },
  customers: (search?: string, kyc?: string) => {
    const params = new URLSearchParams()
    if (search) params.set('search', search)
    if (kyc && kyc !== 'all') params.set('kyc', kyc)
    const qs = params.toString()
    return request<CustomersResponse>(`/customers${qs ? `?${qs}` : ''}`)
  },
  transactions: (search?: string, offset = 0, type?: string) => {
    const params = new URLSearchParams({ offset: String(offset) })
    if (search) params.set('search', search)
    if (type && type !== 'all') params.set('type', type)
    return request<TransactionsResponse>(`/transactions?${params}`)
  },
  analytics: () => request<AnalyticsResponse>('/analytics'),
  supportTickets: (search?: string) =>
    request<SupportTicketsResponse>(
      `/support-tickets${search ? `?search=${encodeURIComponent(search)}` : ''}`,
    ),
  inspect: (query: string) =>
    request<InspectorResponse>(`/inspect?q=${encodeURIComponent(query)}`),
  systemStatus: () => request<SystemStatusResponse>('/system-status'),
  monitoring: (band = 'attention', search?: string, offset = 0) => {
    const params = new URLSearchParams({ band, offset: String(offset) })
    if (search) params.set('search', search)
    return request<MonitoringResponse>(`/monitoring?${params}`)
  },
  saveMonitorRecipients: (emails: string[]) =>
    request<{ recipients: string[] }>('/monitoring/recipients', {
      method: 'POST',
      body: JSON.stringify({ emails }),
    }),
  markScumlReported: (id: number) =>
    request<{ ok: boolean }>('/monitoring/reported', {
      method: 'POST',
      body: JSON.stringify({ id }),
    }),
}

export function formatUsd(amount: number): string {
  return `$${amount.toLocaleString('en-US', { maximumFractionDigits: 0 })}`
}

export function formatTrend(pct: number): string {
  const sign = pct >= 0 ? '+' : ''
  return `${sign}${pct}%`
}
