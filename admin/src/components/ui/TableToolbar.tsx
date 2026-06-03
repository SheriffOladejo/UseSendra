import { Filter, Search } from 'lucide-react'
import { useEffect, useState } from 'react'
import { FilterMenu } from './FilterMenu'

export function TableToolbar({
  title,
  searchPlaceholder = 'Search',
  onSearch,
}: {
  title: string
  searchPlaceholder?: string
  onSearch?: (query: string) => void
}) {
  const [filterOpen, setFilterOpen] = useState(false)
  const [query, setQuery] = useState('')

  useEffect(() => {
    if (!onSearch) return
    const t = window.setTimeout(() => onSearch(query), 300)
    return () => window.clearTimeout(t)
  }, [query, onSearch])

  return (
    <div className="flex flex-col gap-4 border-b border-sendra-border px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
      <h2 className="text-lg font-semibold text-gray-900">{title}</h2>
      <div className="flex items-center gap-3">
        <div className="relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
          <input
            type="search"
            placeholder={searchPlaceholder}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            className="h-10 w-full min-w-[200px] rounded-xl border border-sendra-border bg-sendra-surface pl-10 pr-4 text-sm outline-none focus:border-sendra-primary sm:w-56"
          />
        </div>
        <div className="relative">
          <button
            type="button"
            onClick={() => setFilterOpen((o) => !o)}
            className="inline-flex h-10 items-center gap-2 rounded-xl border border-sendra-border bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50"
          >
            <Filter className="h-4 w-4" />
            Filters
          </button>
          {filterOpen && (
            <>
              <button
                type="button"
                className="fixed inset-0 z-10"
                aria-label="Close filters"
                onClick={() => setFilterOpen(false)}
              />
              <div className="absolute right-0 top-full z-20 mt-2">
                <FilterMenu onClose={() => setFilterOpen(false)} />
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  )
}
