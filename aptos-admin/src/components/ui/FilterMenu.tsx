export type KycFilter = 'all' | 'tier1' | 'tier2' | 'tier3' | 'inactive'

const options: Array<{ value: KycFilter; label: string; showCheckbox?: boolean }> = [
  { value: 'all', label: 'Last Added', showCheckbox: true },
  { value: 'tier1', label: 'KYC STATUS (Tier1)' },
  { value: 'tier2', label: 'KYC STATUS (Tier2)' },
  { value: 'tier3', label: 'KYC STATUS (Tier3)' },
]

export function KycFilterMenu({
  selected,
  onSelect,
  onClose,
}: {
  selected: KycFilter
  onSelect: (value: KycFilter) => void
  onClose?: () => void
}) {
  return (
    <div
      className="min-w-[220px] rounded-2xl border border-sendra-border bg-white p-2 shadow-lg"
      role="menu"
    >
      {options.map((opt) => (
        <button
          key={opt.value}
          type="button"
          role="menuitem"
          onClick={() => {
            onSelect(opt.value)
            onClose?.()
          }}
          className={`flex w-full items-center justify-between rounded-xl px-4 py-3 text-left text-sm text-gray-800 ${
            selected === opt.value ? 'bg-gray-100' : 'hover:bg-gray-50'
          }`}
        >
          {opt.label}
          {opt.showCheckbox && (
            <span className="h-4 w-4 rounded border border-gray-300 bg-white" />
          )}
        </button>
      ))}
    </div>
  )
}

export type TxFilter = 'all' | 'on_ramp' | 'off_ramp' | 'deposit' | 'withdrawal' | 'send' | 'swap'

const txOptions: Array<{ value: TxFilter; label: string }> = [
  { value: 'all', label: 'All types' },
  { value: 'on_ramp', label: 'On-Ramp' },
  { value: 'deposit', label: 'Deposit' },
  { value: 'off_ramp', label: 'Off-ramp' },
  { value: 'withdrawal', label: 'Withdrawal' },
  { value: 'send', label: 'Send' },
  { value: 'swap', label: 'Swap' },
]

export function TxFilterMenu({
  selected,
  onSelect,
  onClose,
}: {
  selected: TxFilter
  onSelect: (value: TxFilter) => void
  onClose?: () => void
}) {
  return (
    <div
      className="min-w-[180px] rounded-2xl border border-sendra-border bg-white p-2 shadow-lg"
      role="menu"
    >
      {txOptions.map((opt) => (
        <button
          key={opt.value}
          type="button"
          role="menuitem"
          onClick={() => {
            onSelect(opt.value)
            onClose?.()
          }}
          className={`flex w-full rounded-xl px-4 py-3 text-left text-sm text-gray-800 ${
            selected === opt.value ? 'bg-gray-100' : 'hover:bg-gray-50'
          }`}
        >
          {opt.label}
        </button>
      ))}
    </div>
  )
}
