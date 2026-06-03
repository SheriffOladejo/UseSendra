"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
/**
 * One-off: generate new Aptos wallets for Sendra-native users and update DB.
 *
 *   npx tsx src/scripts/rotate-wallets.ts --dry-run
 *   npx tsx src/scripts/rotate-wallets.ts
 *
 * Requires NEW_ENCRYPTION_KEY in aptos-service/.env (used via keyManager).
 * Loads DB credentials from repo root .env (APTOS_DB_*).
 */
const fs_1 = __importDefault(require("fs"));
const path_1 = __importDefault(require("path"));
const url_1 = require("url");
const dotenv_1 = __importDefault(require("dotenv"));
const promise_1 = __importDefault(require("mysql2/promise"));
const ts_sdk_1 = require("@aptos-labs/ts-sdk");
const keyManager_js_1 = require("../services/keyManager.js");
const __dirname = path_1.default.dirname((0, url_1.fileURLToPath)(import.meta.url));
dotenv_1.default.config({ path: path_1.default.resolve(__dirname, '../../../.env') });
dotenv_1.default.config({ path: path_1.default.resolve(__dirname, '../../.env') });
async function main() {
    const dryRun = process.argv.includes('--dry-run');
    if (!process.env.NEW_ENCRYPTION_KEY?.trim()) {
        throw new Error('NEW_ENCRYPTION_KEY must be set in aptos-service/.env');
    }
    const dbHost = process.env.APTOS_DB_HOST || 'localhost';
    const dbName = process.env.APTOS_DB_NAME || 'sendra_db';
    const dbUser = process.env.APTOS_DB_USER || 'developer';
    const dbPass = process.env.APTOS_DB_PASS || '';
    const conn = await promise_1.default.createConnection({
        host: dbHost,
        user: dbUser,
        password: dbPass,
        database: dbName,
    });
    const [rows] = await conn.execute(`SELECT id, phone_number, username, aptos_address, encrypted_private_key
     FROM users
     WHERE account_type = 'sendra'
       AND encrypted_private_key IS NOT NULL
       AND encrypted_private_key != ''
     ORDER BY id ASC`);
    console.log(`Found ${rows.length} Sendra user(s) to rotate.${dryRun ? ' (dry-run)' : ''}`);
    const audit = [];
    let ok = 0;
    let fail = 0;
    for (const row of rows) {
        const phone = String(row.phone_number);
        const oldAddress = String(row.aptos_address ?? '');
        try {
            const account = ts_sdk_1.Account.generate();
            const newAddress = account.accountAddress.toString();
            const encryptedKey = (0, keyManager_js_1.encryptKey)(account.privateKey.toString());
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
                await conn.execute(`UPDATE users
           SET aptos_address = ?, encrypted_private_key = ?
           WHERE phone_number = ?`, [newAddress, encryptedKey, phone]);
            }
            ok++;
        }
        catch (e) {
            fail++;
            const msg = e instanceof Error ? e.message : String(e);
            console.error(`FAIL ${phone}: ${msg}`);
        }
    }
    await conn.end();
    const backupDir = path_1.default.resolve(__dirname, '../../../backups');
    fs_1.default.mkdirSync(backupDir, { recursive: true });
    const stamp = new Date().toISOString().replace(/[:.]/g, '-');
    const auditPath = path_1.default.join(backupDir, `wallet_rotation_audit_${stamp}.json`);
    fs_1.default.writeFileSync(auditPath, JSON.stringify({ dry_run: dryRun, ok, fail, users: audit }, null, 2));
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
