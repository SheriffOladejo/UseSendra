/**
 * One-off: generate new Aptos wallets for Sendra-native users and update DB.
 *
 *   npx tsx src/scripts/rotate-wallets.ts --dry-run
 *   npx tsx src/scripts/rotate-wallets.ts
 *
 * Requires NEW_ENCRYPTION_KEY in aptos-service/.env (used via keyManager).
 * Loads DB credentials from repo root .env (APTOS_DB_*).
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import dotenv from 'dotenv';
import mysql from 'mysql2/promise';
import { Account } from '@aptos-labs/ts-sdk';
import { encryptKey } from '../services/keyManager.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
dotenv.config({ path: path.resolve(__dirname, '../../../.env') });
dotenv.config({ path: path.resolve(__dirname, '../../.env') });

async function main(): Promise<void> {
  const dryRun = process.argv.includes('--dry-run');

  if (!process.env.NEW_ENCRYPTION_KEY?.trim()) {
    throw new Error('NEW_ENCRYPTION_KEY must be set in aptos-service/.env');
  }

  const dbHost = process.env.APTOS_DB_HOST || 'localhost';
  const dbName = process.env.APTOS_DB_NAME || 'sendra_db';
  const dbUser = process.env.APTOS_DB_USER || 'developer';
  const dbPass = process.env.APTOS_DB_PASS || '';

  const conn = await mysql.createConnection({
    host: dbHost,
    user: dbUser,
    password: dbPass,
    database: dbName,
  });

  const [rows] = await conn.execute<
    mysql.RowDataPacket[]
  >(
    `SELECT id, phone_number, username, aptos_address, encrypted_private_key
     FROM users
     WHERE account_type = 'sendra'
       AND encrypted_private_key IS NOT NULL
       AND encrypted_private_key != ''
     ORDER BY id ASC`,
  );

  console.log(`Found ${rows.length} Sendra user(s) to rotate.${dryRun ? ' (dry-run)' : ''}`);

  const audit: Array<{
    user_id: number;
    phone_number: string;
    username: string;
    old_aptos_address: string;
    new_aptos_address: string;
    rotated_at: string;
  }> = [];

  let ok = 0;
  let fail = 0;

  for (const row of rows) {
    const phone = String(row.phone_number);
    const oldAddress = String(row.aptos_address ?? '');

    try {
      const account = Account.generate();
      const newAddress = account.accountAddress.toString();
      const encryptedKey = encryptKey(account.privateKey.toString());

      audit.push({
        user_id: Number(row.id),
        phone_number: phone,
        username: String(row.username ?? ''),
        old_aptos_address: oldAddress,
        new_aptos_address: newAddress,
        rotated_at: new Date().toISOString(),
      });

      console.log(`${phone} (${row.username}): ${oldAddress} -> ${newAddress}`);

      if (!dryRun) {
        await conn.execute(
          `UPDATE users
           SET aptos_address = ?, encrypted_private_key = ?
           WHERE phone_number = ?`,
          [newAddress, encryptedKey, phone],
        );
      }

      ok++;
    } catch (e: unknown) {
      fail++;
      const msg = e instanceof Error ? e.message : String(e);
      console.error(`FAIL ${phone}: ${msg}`);
    }
  }

  await conn.end();

  const backupDir = path.resolve(__dirname, '../../../backups');
  fs.mkdirSync(backupDir, { recursive: true });
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const auditPath = path.join(backupDir, `wallet_rotation_audit_${stamp}.json`);
  fs.writeFileSync(auditPath, JSON.stringify({ dry_run: dryRun, ok, fail, users: audit }, null, 2));
  console.log(`Audit log: ${auditPath}`);
  console.log(`Done: ${ok} ok, ${fail} failed`);

  if (fail > 0) {
    process.exit(1);
  }
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
