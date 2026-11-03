import { Check, Copy } from 'lucide-react'
import { useState } from 'react'

export function CopyButton({ value }: { value: string }) {
  const [copied, setCopied] = useState(false)

  if (!value || value === '—') return null

  async function handleCopy() {
    try {
      await navigator.clipboard.writeText(value)
      setCopied(true)
      window.setTimeout(() => setCopied(false), 1500)
    } catch {
      /* ignore */
    }
  }

  return (
    <button
      type="button"
      onClick={handleCopy}
      className="ml-1 inline-flex rounded p-0.5 text-gray-400 hover:text-gray-600"
      aria-label="Copy"
    >
      {copied ? <Check className="h-3.5 w-3.5 text-emerald-600" /> : <Copy className="h-3.5 w-3.5" />}
    </button>
  )
}
