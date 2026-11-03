import { useEffect, useState } from 'react'
import { api } from '../lib/api'

export function SettingsPage() {
  const [emails, setEmails] = useState('')
  const [saved, setSaved] = useState('')
  const [error, setError] = useState('')

  useEffect(() => {
    api
      .monitoring('attention')
      .then((data) => setEmails(data.recipients.join('\n')))
      .catch((e: Error) => setError(e.message))
  }, [])

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-gray-900">Settings</h1>
      <form
        className="max-w-xl rounded-2xl border border-sendra-border bg-white p-6 shadow-sm"
        onSubmit={(event) => {
          event.preventDefault()
          setSaved('')
          setError('')
          const list = emails
            .split(/[\s,]+/)
            .map((email) => email.trim())
            .filter(Boolean)
          api
            .saveMonitorRecipients(list)
            .then((res) => {
              setEmails(res.recipients.join('\n'))
              setSaved('Alert emails saved.')
            })
            .catch((e: Error) => setError(e.message))
        }}
      >
        <h2 className="text-sm font-semibold text-gray-900">Risk alert emails</h2>
        <p className="mt-1 text-sm text-sendra-muted">
          These addresses are emailed when a transaction scores 6 or higher, and when it must be filed on SCUML: above 10,000 USD or 10,000 USDC, above ₦5,000,000 for an individual, or above ₦10,000,000 for a business.
        </p>
        <textarea
          value={emails}
          onChange={(e) => setEmails(e.target.value)}
          rows={5}
          placeholder="compliance@usesendra.com"
          className="mt-4 w-full rounded-lg border border-sendra-border px-3 py-2 text-sm"
        />
        {saved && <p className="mt-3 text-sm text-emerald-700">{saved}</p>}
        {error && <p className="mt-3 text-sm text-red-600">{error}</p>}
        <button
          type="submit"
          className="mt-4 rounded-lg bg-sendra-primary px-4 py-2 text-sm font-medium text-white"
        >
          Save
        </button>
      </form>
    </div>
  )
}
