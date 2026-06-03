import { MoreVertical } from 'lucide-react'
import { useState } from 'react'

type MenuItem = { label: string; onClick?: () => void }

const defaultItems: MenuItem[] = [
  { label: 'View Profile Details' },
  { label: 'Block User' },
]

export function ActionMenu({ items = defaultItems }: { items?: MenuItem[] }) {
  const [open, setOpen] = useState(false)

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100"
        aria-label="Actions"
      >
        <MoreVertical className="h-5 w-5" />
      </button>
      {open && (
        <>
          <button
            type="button"
            className="fixed inset-0 z-10"
            aria-label="Close menu"
            onClick={() => setOpen(false)}
          />
          <div className="absolute right-0 top-full z-20 mt-1 min-w-[200px] rounded-2xl border border-sendra-border bg-white p-2 shadow-lg">
            {items.map((item) => (
              <button
                key={item.label}
                type="button"
                className="w-full rounded-xl px-4 py-3 text-left text-sm text-gray-800 hover:bg-gray-100"
                onClick={() => {
                  item.onClick?.()
                  setOpen(false)
                }}
              >
                {item.label}
              </button>
            ))}
          </div>
        </>
      )}
    </div>
  )
}
