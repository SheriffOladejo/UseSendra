import crypto from 'crypto';

const ACTIVE_KEY_HEX =
  process.env.NEW_ENCRYPTION_KEY?.trim() || process.env.ENCRYPTION_KEY?.trim();

if (!ACTIVE_KEY_HEX) {
  throw new Error('ENCRYPTION_KEY or NEW_ENCRYPTION_KEY must be set in .env');
}

const ENCRYPTION_KEY = Buffer.from(ACTIVE_KEY_HEX, 'hex');

if (ENCRYPTION_KEY.length !== 32) {
  throw new Error('Active encryption key must be exactly 32 bytes (64 hex characters)');
}

const ALGORITHM = 'aes-256-gcm';

export function encryptKey(privateKey) {
  const iv = crypto.randomBytes(16);
  const cipher = crypto.createCipheriv(ALGORITHM, ENCRYPTION_KEY, iv);
  const encrypted = Buffer.concat([cipher.update(privateKey, 'utf8'), cipher.final()]);
  const tag = cipher.getAuthTag();
  return [iv.toString('hex'), tag.toString('hex'), encrypted.toString('hex')].join(':');
}

export function decryptKey(encryptedData) {
  const parts = String(encryptedData).split(':');
  if (parts.length !== 3) {
    throw new Error('Invalid encrypted key format. Expected iv:tag:data');
  }
  const [ivHex, tagHex, encryptedHex] = parts;
  const decipher = crypto.createDecipheriv(ALGORITHM, ENCRYPTION_KEY, Buffer.from(ivHex, 'hex'));
  decipher.setAuthTag(Buffer.from(tagHex, 'hex'));
  return decipher.update(Buffer.from(encryptedHex, 'hex')).toString('utf8') + decipher.final('utf8');
}
