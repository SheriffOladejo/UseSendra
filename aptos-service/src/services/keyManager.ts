// src/services/keyManager.ts
import crypto from 'crypto';
import 'dotenv/config';

if (!process.env.ENCRYPTION_KEY) {
  throw new Error('ENCRYPTION_KEY is not set in .env');
}

const ENCRYPTION_KEY = Buffer.from(process.env.ENCRYPTION_KEY, 'hex');

if (ENCRYPTION_KEY.length !== 32) {
  throw new Error('ENCRYPTION_KEY must be exactly 32 bytes (64 hex characters)');
}

const ALGORITHM = 'aes-256-gcm';

/**
 * Encrypts a private key string.
 * Returns a colon-separated string: iv:authTag:encryptedData (all hex)
 */
export function encryptKey(privateKey: string): string {
  const iv = crypto.randomBytes(16);
  const cipher = crypto.createCipheriv(ALGORITHM, ENCRYPTION_KEY, iv);
  const encrypted = Buffer.concat([
    cipher.update(privateKey, 'utf8'),
    cipher.final(),
  ]);
  const tag = cipher.getAuthTag();
  return [iv.toString('hex'), tag.toString('hex'), encrypted.toString('hex')].join(':');
}

/**
 * Decrypts an encrypted private key string.
 * Input must be the colon-separated format produced by encryptKey.
 */
export function decryptKey(encryptedData: string): string {
  const parts = encryptedData.split(':');
  if (parts.length !== 3) {
    throw new Error('Invalid encrypted key format. Expected iv:tag:data');
  }
  const [ivHex, tagHex, encryptedHex] = parts;
  const iv = Buffer.from(ivHex, 'hex');
  const tag = Buffer.from(tagHex, 'hex');
  const encrypted = Buffer.from(encryptedHex, 'hex');
  const decipher = crypto.createDecipheriv(ALGORITHM, ENCRYPTION_KEY, iv);
  decipher.setAuthTag(tag);
  return (
    decipher.update(encrypted).toString('utf8') + decipher.final('utf8')
  );
}