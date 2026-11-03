import { Hourglass, Users } from 'lucide-react'
import { useEffect, useState } from 'react'
import { ActionMenu } from '../components/ui/ActionMenu'
import { Badge, kycToVariant } from '../components/ui/Badge'
import { CopyButton } from '../components/ui/CopyButton'
import { KycFilterMenu, type KycFilter } from '../components/ui/FilterMenu'
import { ErrorState, LoadingState } from '../components/ui/LoadingState'
import { StatCard } from '../components/ui/StatCard'
import { TableToolbar } from '../components/ui/TableToolbar'
import { api, formatTrend, type CustomerKycResponse, type CustomersResponse } from '../lib/api'

export function CustomersPage() {
  const [data, setData] = useState<CustomersResponse | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [kycFilter, setKycFilter] = useState<KycFilter>('all')
  const [kyc, setKyc] = useState<CustomerKycResponse | null>(null)
  const [kycError, setKycError] = useState('')
  const [kycLoading, setKycLoading] = useState(false)

  function openKyc(id: number) {
    setKyc(null)
    setKycError('')
    setKycLoading(true)
    api
      .customerKyc(id)
      .then(setKyc)
      .catch((e: Error) => setKycError(e.message))
      .finally(() => setKycLoading(false))
  }

  useEffect(() => {
    setLoading(true)
    api
      .customers(search || undefined, kycFilter)
      .then(setData)
      .catch((e: Error) => setError(e.message))
      .finally(() => setLoading(false))
  }, [search, kycFilter])

  if (loading && !data) return <LoadingState />
  if (error && !data) return <ErrorState message={error} />
  if (!data) return null

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-gray-900">Customers</h1>
      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <StatCard
          label="Total Unique Customers"
          value={data.stats.totalCustomers.toLocaleString()}
          trend={`${formatTrend(26)} 24hrs`}
          icon={Users}
        />
        <StatCard
          label="Active customers"
          value={data.stats.activeCustomers.toLocaleString()}
          trend={`${formatTrend(26)} 24hrs`}
          icon={Users}
        />
        <StatCard
          label="KYC verified"
          value={`${data.stats.kycVerified}/${data.stats.kycTotal}`}
          trend={`${formatTrend(0)} This month`}
          icon={Hourglass}
        />
      </div>
      <section className="overflow-hidden rounded-2xl border border-sendra-border bg-white shadow-sm">
        <TableToolbar
          title="All Customers"
          searchPlaceholder="Search Users"
          onSearch={setSearch}
          filterMenu={(onClose) => (
            <KycFilterMenu
              selected={kycFilter}
              onSelect={setKycFilter}
              onClose={onClose}
            />
          )}
        />
        <div className="overflow-x-auto">
          <table className="w-full min-w-[800px] text-left text-sm">
            <thead>
              <tr className="border-b border-sendra-border text-sendra-muted">
                <th className="px-6 py-3 font-medium">Id</th>
                <th className="px-6 py-3 font-medium">Name</th>
                <th className="px-6 py-3 font-medium">Number</th>
                <th className="px-6 py-3 font-medium">Kyc Status</th>
                <th className="px-6 py-3 font-medium">Wallet Address</th>
                <th className="px-6 py-3 font-medium">Action</th>
              </tr>
            </thead>
            <tbody>
              {data.customers.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-6 py-8 text-center text-sendra-muted">
                    No customers found.
                  </td>
                </tr>
              ) : (
                data.customers.map((row) => (
                  <tr key={row.id} className="border-b border-sendra-border last:border-0">
                    <td className="px-6 py-4 text-gray-600">{row.id}</td>
                    <td className="px-6 py-4 font-medium text-gray-900">{row.name}</td>
                    <td className="px-6 py-4 text-gray-600">{row.number}</td>
                    <td className="px-6 py-4">
                      <Badge variant={kycToVariant(row.kycStatus)}>{row.kycStatus}</Badge>
                    </td>
                    <td className="px-6 py-4 font-mono text-gray-600">
                      <span className="inline-flex items-center">
                        {row.walletShort}
                        <CopyButton value={row.wallet} />
                      </span>
                    </td>
                    <td className="px-6 py-4">
                      <ActionMenu
                        items={[
                          ...(row.kycStatus.startsWith('Tier') && row.kycStatus !== 'Tier 0'
                            ? [{ label: 'View KYC', onClick: () => openKyc(row.id) }]
                            : []),
                          { label: 'Contact' },
                          { label: 'Block User', danger: true },
                        ]}
                      />
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </section>
      {(kycLoading || kyc || kycError) && (
        <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
          <div className="max-h-[85vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-xl">
            <div className="mb-4 flex items-start justify-between gap-4">
              <div>
                <h2 className="text-lg font-semibold text-gray-900">KYC</h2>
                {kyc && (
                  <p className="text-sm text-sendra-muted">
                    {kyc.name} · {kyc.phone} · {kyc.kycStatus}
                  </p>
                )}
              </div>
              <button
                type="button"
                className="rounded-lg px-2 py-1 text-sm text-gray-600 hover:bg-gray-100"
                onClick={() => {
                  setKyc(null)
                  setKycError('')
                  setKycLoading(false)
                }}
              >
                Close
              </button>
            </div>
            {kycLoading && <p className="text-sm text-sendra-muted">Loading KYC…</p>}
            {kycError && <p className="text-sm text-red-600">{kycError}</p>}
            {kyc && kyc.tiers.length === 0 && (
              <p className="text-sm text-sendra-muted">This customer has not completed KYC.</p>
            )}
            {kyc?.tiers.map((tier) => (
              <section key={tier.tier} className="mb-4 border-t border-sendra-border pt-4">
                <h3 className="mb-2 text-sm font-semibold text-gray-900">{tier.label}</h3>
                <dl className="grid gap-2 text-sm sm:grid-cols-2">
                  {tier.fields.map((field) => (
                    <div key={field.label}>
                      <dt className="text-sendra-muted">{field.label}</dt>
                      <dd className="text-gray-900">
                        {field.proof && kyc ? (
                          <button
                            type="button"
                            className="text-sendra-primary underline"
                            onClick={() => {
                              api.openCustomerKycProof(kyc.id).catch(() => undefined)
                            }}
                          >
                            View document
                          </button>
                        ) : (
                          field.value || '—'
                        )}
                      </dd>
                    </div>
                  ))}
                </dl>
              </section>
            ))}
            {kyc?.verifiedAt && (
              <p className="text-xs text-sendra-muted">Last verified {kyc.verifiedAt}</p>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
