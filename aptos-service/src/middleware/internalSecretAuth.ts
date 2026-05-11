import { Request, Response, NextFunction } from 'express';

function readHeaderSecret(req: Request): string {
  const raw = req.headers['x-internal-secret'];
  if (raw === undefined) {
    return '';
  }
  const s = Array.isArray(raw) ? raw[0] : raw;

  return typeof s === 'string' ? s.trim() : '';
}

/**
 * Matches PHP {@see MessageController::aptosServiceAuthHeaders} (trimmed secret).
 */
export function requireInternalSecret(req: Request, res: Response, next: NextFunction): void {
  const got = readHeaderSecret(req);
  const want = (process.env.INTERNAL_API_SECRET ?? '').trim();
  if (got !== want) {
    res.status(401).json({ success: false, error: 'Unauthorized' });
    return;
  }
  next();
}
