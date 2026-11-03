export function requireInternalSecret(req, res, next) {
  const want = (process.env.INTERNAL_API_SECRET ?? '').trim();
  if (want === '') {
    res.status(503).json({ success: false, error: 'INTERNAL_API_SECRET is not configured' });
    return;
  }
  const raw = req.headers['x-internal-secret'];
  const got = (Array.isArray(raw) ? raw[0] : raw || '').trim();
  if (got === '' || got !== want) {
    res.status(401).json({ success: false, error: 'Unauthorized' });
    return;
  }
  next();
}
