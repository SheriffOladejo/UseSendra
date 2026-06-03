import { useState } from 'react'

const options = ['Last Added', 'Inflow', 'Outflow'] as const

export function FilterMenu({ onClose }: { onClose?: () => void }) {
  const [selected, setSelected] = useState<string>('Inflow')

  return (
    <div
      className="min-w-[180px] rounded-2xl border border-sendra-border bg-white p-2 shadow-lg"
      role="menu"
    >
      {options.map((opt) => (
        <button
          key={opt}
          type="button"
          role="menuitem"
          onClick={() => {
            setSelected(opt)
            onClose?.()
          }}
          className={`flex w-full items-center justify-between rounded-xl px-4 py-3 text-left text-sm text-gray-800 ${
            selected === opt ? 'bg-gray-100' : 'hover:bg-gray-50'
          }`}
        >
          {opt}
          {opt === 'Last Added' && (
            <span className="h-4 w-4 rounded border border-gray-300 bg-white" />
          )}
        </button>
      ))}
    </div>
  )
}
