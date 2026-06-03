import { ChevronLeft, ChevronRight } from 'lucide-react'

export function Pagination({
  page = 1,
  total = 1,
  onPageChange,
}: {
  page?: number
  total?: number
  onPageChange?: (page: number) => void
}) {
  const windowSize = 6
  const start = Math.max(1, page - 2)
  const end = Math.min(total, start + windowSize - 1)
  const pages = Array.from({ length: end - start + 1 }, (_, i) => start + i)

  return (
    <div className="flex flex-col items-center justify-between gap-4 border-t border-sendra-border px-6 py-4 sm:flex-row">
      <p className="text-sm text-sendra-muted">
        Page {page} of {total}
      </p>
      <div className="flex items-center gap-1">
        {pages.map((n) => (
          <button
            key={n}
            type="button"
            onClick={() => onPageChange?.(n)}
            className={`flex h-9 w-9 items-center justify-center rounded-lg text-sm font-medium ${
              n === page
                ? 'border-2 border-sendra-primary text-sendra-primary'
                : 'text-gray-600 hover:bg-gray-100'
            }`}
          >
            {n}
          </button>
        ))}
      </div>
      <div className="flex gap-2">
        <button
          type="button"
          disabled={page <= 1}
          onClick={() => onPageChange?.(page - 1)}
          className="inline-flex h-9 items-center gap-1 rounded-lg bg-sendra-primary px-3 text-sm font-medium text-white disabled:opacity-50"
        >
          <ChevronLeft className="h-4 w-4" />
          Previous
        </button>
        <button
          type="button"
          disabled={page >= total}
          onClick={() => onPageChange?.(page + 1)}
          className="inline-flex h-9 items-center gap-1 rounded-lg bg-sendra-primary px-3 text-sm font-medium text-white disabled:opacity-50"
        >
          Next
          <ChevronRight className="h-4 w-4" />
        </button>
      </div>
    </div>
  )
}
