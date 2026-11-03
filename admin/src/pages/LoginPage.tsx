import { useState } from 'react'
import { Eye, EyeOff } from 'lucide-react'
import { clearAdminSecret, setAdminSecret } from '../lib/api'
import { Logo } from '../components/layout/Logo'

export function LoginPage({ onSuccess }: { onSuccess: () => void }) {
  const [secret, setSecret] = useState('')
  const [showPassword, setShowPassword] = useState(false)
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
        <div className="relative mt-2">
          <input
            id="password"
            type={showPassword ? 'text' : 'password'}
            value={secret}
            onChange={(e) => setSecret(e.target.value)}
            className="w-full rounded-xl border border-sendra-border px-4 py-3 pr-11 text-sm outline-none focus:border-sendra-primary"
            autoComplete="current-password"
            required
          />
          <button
            type="button"
            onClick={() => setShowPassword((prev) => !prev)}
            className="absolute right-3 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600 focus:outline-none"
            aria-label={showPassword ? 'Hide password' : 'Show password'}
          >
            {showPassword ? (
              <EyeOff className="h-5 w-5" />
            ) : (
              <Eye className="h-5 w-5" />
            )}
          </button>
        </div>
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
