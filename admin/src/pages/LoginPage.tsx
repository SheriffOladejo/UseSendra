import { useState } from 'react'
import { clearAdminSecret, setAdminSecret } from '../lib/api'
import { Logo } from '../components/layout/Logo'

export function LoginPage({ onSuccess }: { onSuccess: () => void }) {
  const [secret, setSecret] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setError('')
    setLoading(true)
    setAdminSecret(secret.trim())

    try {
      const res = await fetch('/logistics/api/overview', {
        headers: { 'X-Admin-Secret': secret.trim() },
      })
      if (!res.ok) {
        clearAdminSecret()
        setError('Invalid password')
        return
      }
      onSuccess()
    } catch {
      setError('Could not reach API')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-sendra-surface p-6">
      <form
        onSubmit={handleSubmit}
        className="w-full max-w-md rounded-2xl border border-sendra-border bg-white p-8 shadow-sm"
      >
        <Logo size="large" className="flex justify-center" />
        <h1 className="mt-6 text-xl font-bold text-gray-900">Admin sign in</h1>
        <p className="mt-2 text-sm text-sendra-muted">
          Enter the logistics admin password.
        </p>
        <label className="mt-6 block text-sm font-medium text-gray-700" htmlFor="password">
          Password
        </label>
        <input
          id="password"
          type="password"
          value={secret}
          onChange={(e) => setSecret(e.target.value)}
          className="mt-2 w-full rounded-xl border border-sendra-border px-4 py-3 text-sm outline-none focus:border-sendra-primary"
          autoComplete="current-password"
          required
        />
        {error && <p className="mt-3 text-sm text-red-600">{error}</p>}
        <button
          type="submit"
          disabled={loading}
          className="mt-6 w-full rounded-xl bg-sendra-primary py-3 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-60"
        >
          {loading ? 'Signing in…' : 'Continue'}
        </button>
      </form>
    </div>
  )
}
