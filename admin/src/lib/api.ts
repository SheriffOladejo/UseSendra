const API_BASE = import.meta.env.VITE_API_BASE ?? '/logistics/api'
const STORAGE_KEY = 'sendra_admin_secret'

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
    totalUsers: number
    activeRiders: number
    totalRiders: number
    pendingVerification: number
    revenueNgn: number
    totalOrders: number
    successRate: number
  }
  recentActivities: Array<{
    kind: string
    title: string
    meta: string
    time: string
  }>
}

export type CustomersResponse = {
  stats: { totalCustomers: number; totalOrders: number; averageRating: number }
  customers: Array<{
    id: number
    name: string
    number: string
    status: string
    orders: number
    avgRating: number
  }>
}

export type RidersResponse = {
  stats: {
    totalRiders: number
    pendingVerification: number
    totalDeliveries: number
    totalEarningsNgn: number
    averageRating: number
  }
  riders: Array<{
    id: number
    name: string
    number: string
    verification: string
    vehicle: string
    vehicleDetail: string
    location: string
    trips: number
    rating: number
  }>
}

export type OrdersResponse = {
  stats: {
    total: number
    completed: number
    inTransit: number
    pending: number
    cancelled: number
  }
  orders: Array<{
    id: string
    orderId: number
    customer: string
    status: string
    pickup: string
    dropoff: string
    amount: string
    createdAt: string
  }>
}

export type AnalyticsResponse = {
  stats: { totalInflowNgn: number; totalOutflowNgn: number; balanceNgn: number }
  pagination: { page: number; pageSize: number; total: number; totalPages: number }
  transactions: Array<{
    id: string
    amount: string
    time: string
    type: string
    tx: string | null
  }>
}

export const api = {
  overview: () => request<OverviewResponse>('/overview'),
  customers: (search?: string) =>
    request<CustomersResponse>(`/customers${search ? `?search=${encodeURIComponent(search)}` : ''}`),
  riders: (search?: string) =>
    request<RidersResponse>(`/riders${search ? `?search=${encodeURIComponent(search)}` : ''}`),
  orders: (search?: string) =>
    request<OrdersResponse>(`/orders${search ? `?search=${encodeURIComponent(search)}` : ''}`),
  analytics: (search?: string, offset = 0) =>
    request<AnalyticsResponse>(
      `/analytics?offset=${offset}${search ? `&search=${encodeURIComponent(search)}` : ''}`,
    ),
  verifyRider: (driverId: number, action: 'approve' | 'reject' | 'approve_test') =>
    request<{ ok: boolean }>(`/riders/${driverId}/verification`, {
      method: 'POST',
      body: JSON.stringify({ action }),
    }),
}

export function formatNgn(amount: number, compact = false): string {
  if (compact && amount >= 1_000_000) {
    return `₦${(amount / 1_000_000).toFixed(1)}M`
  }
  return `₦${amount.toLocaleString('en-NG', { maximumFractionDigits: 0 })}`
}

export function formatUsdFromNgn(ngn: number): string {
  return `$${ngn.toLocaleString('en-US', { maximumFractionDigits: 0 })}`
}
