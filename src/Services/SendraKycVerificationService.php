<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/**
 * Orchestrates Prembly KYC checks, user tier updates, and WhatsApp notifications.
 *
 * Tier 1: NIN + full name + phone match
 * Tier 2: BVN + liveness + match Tier 1 name + phone match
 * Tier 3: Proof of address
 */
final class SendraKycVerificationService
{
    public function __construct(
        private ?PremblyVerificationService $prembly = null,
        private ?SendraKycService $kyc = null,
        private ?SendraKycPhoneMatcher $phones = null,
        private ?SendraKycNameMatcher $names = null,
        private ?SendraKycCrypto $crypto = null,
    ) {
        $this->prembly = $prembly ?? new PremblyVerificationService();
        $this->kyc = $kyc ?? new SendraKycService();
        $this->phones = $phones ?? new SendraKycPhoneMatcher();
        $this->names = $names ?? new SendraKycNameMatcher();
        $this->crypto = $crypto ?? new SendraKycCrypto();
    }

    /**
     * @return array<string, string>
     */
    public static function sourceOfWealthOptions(): array
    {
        return [
            'salary' => 'Salary or employment',
            'business' => 'Business income',
            'savings' => 'Savings',
            'investment' => 'Investment',
            'family' => 'Gift or family support',
            'pension' => 'Pension',
            'other' => 'Other',
        ];
    }

    /**
     * @param array{tmp_name: string, name: string, size: int, type: string}|null $proofFile
     * @return array{ok: bool, error?: string, tier?: int}
     */
    public function completeTier1(
        User $userModel,
        WhatsAppService $whatsapp,
        string $phone,
        string $nin,
        string $fullName,
        string $sourceOfWealth = '',
        ?array $proofFile = null
    ): array {
        $sourceOfWealth = strtolower(trim($sourceOfWealth));
        $sources = self::sourceOfWealthOptions();
        if (!isset($sources[$sourceOfWealth])) {
            return ['ok' => false, 'error' => 'Choose your source of wealth.'];
        }

        $proofCheck = $this->validateDocumentUpload(
            $proofFile,
            'Upload a receipt, invoice, payslip, or statement for your source of wealth.',
            'Upload the proof as a PDF, JPG, or PNG.'
        );
        if (!$proofCheck['ok']) {
            return ['ok' => false, 'error' => $proofCheck['error'] ?? 'Invalid proof document.'];
        }

        $fullName = trim($fullName);
        if ($fullName === '') {
            return ['ok' => false, 'error' => 'Enter your full legal name as it appears on your NIN.'];
        }

        $parsed = $this->fetchNinRecord($nin);
        if (!$parsed['ok']) {
            return ['ok' => false, 'error' => $parsed['error'] ?? 'NIN verification failed.'];
        }

        $apiName = (string) ($parsed['full_name'] ?? '');
        if ($apiName === '' || !$this->names->matches($apiName, $fullName)) {
            return ['ok' => false, 'error' => 'Full name does not match your NIN record. Enter every name on your NIN — order does not matter.'];
        }

        $recordPhone = (string) ($parsed['phone'] ?? '');
        if ($recordPhone === '' || !$this->phones->matches($phone, $recordPhone)) {
            return ['ok' => false, 'error' => $this->phones->mismatchMessage()];
        }

        $ninDigits = (string) ($parsed['nin_digits'] ?? '');
        $storedProof = $this->storeSourceOfWealthProof($proofFile, (string) ($proofCheck['ext'] ?? 'bin'));
        if ($storedProof === null) {
            return ['ok' => false, 'error' => 'Could not save your proof. Try again.'];
        }

        $this->ensureSourceOfWealthColumns($userModel);
        $update = $this->filterExistingColumns($userModel, array_merge([
            'kyc_verified' => 1,
            'kyc_tier' => SendraKycService::TIER_BVN,
            'kyc_full_name' => $fullName,
            'kyc_verified_at' => date('Y-m-d H:i:s'),
            'kyc_source_of_wealth' => $sourceOfWealth,
            'kyc_sow_proof_file' => $storedProof,
        ], $this->verifiedIdentity('nin', $ninDigits, $apiName, $recordPhone)));

        if (!$userModel->update($phone, $update)) {
            @unlink(dirname(__DIR__, 2) . '/var/kyc-sow/' . $storedProof);
            error_log('KYC Tier 1: user update failed for ' . $phone);

            return ['ok' => false, 'error' => 'Could not save verification. Try again or contact support@usesendra.com.'];
        }

        $this->notifyTierApproved($whatsapp, $phone, SendraKycService::TIER_BVN);
        $this->finishKycChatFlow($userModel, $whatsapp, $phone);

        return ['ok' => true, 'tier' => SendraKycService::TIER_BVN];
    }

    /**
     * Tier 2 — BVN + liveness (new flow) or NIN + liveness (legacy Tier 1 was BVN).
     *
     * @return array{ok: bool, error?: string, tier?: int}
     */
    public function completeTier2(User $userModel, WhatsAppService $whatsapp, string $phone, string $bvn, string $nin, string $imageBase64): array
    {
        $user = $userModel->findByPhone($phone);
        if (!is_array($user)) {
            return ['ok' => false, 'error' => 'Account not found.'];
        }

        if ($this->kyc->getUserTier($user) < SendraKycService::TIER_BVN) {
            return ['ok' => false, 'error' => 'Complete Tier 1 verification first.'];
        }

        $hasNin = !empty($user['nin_last4']) || !empty($user['nin']);
        $hasBvn = !empty($user['bvn_last4']) || !empty($user['bvn']);

        if ($hasNin && !$hasBvn) {
            return $this->completeTier2WithBvn($userModel, $whatsapp, $phone, $user, $bvn, $imageBase64);
        }

        if ($hasBvn && !$hasNin) {
            return $this->completeTier2WithNinLegacy($userModel, $whatsapp, $phone, $user, $nin, $imageBase64);
        }

        return ['ok' => false, 'error' => 'Tier 2 verification is not available for your account state. Contact support@usesendra.com.'];
    }

    /**
     * @param array<string, mixed> $user
     * @return array{ok: bool, error?: string, tier?: int}
     */
    private function completeTier2WithBvn(
        User $userModel,
        WhatsAppService $whatsapp,
        string $phone,
        array $user,
        string $bvn,
        string $imageBase64,
    ): array {
        $tier1Name = trim((string) ($user['kyc_full_name'] ?? ''));
        if ($tier1Name === '') {
            return ['ok' => false, 'error' => 'Missing verified name from Tier 1. Complete Tier 1 again or contact support@usesendra.com.'];
        }

        if (!$this->prembly->isConfigured()) {
            return ['ok' => false, 'error' => 'Verification is temporarily unavailable. Try again later.'];
        }

        $image = $this->normalizeImage($imageBase64);
        if ($image === '') {
            return ['ok' => false, 'error' => 'Live photo capture failed. Allow camera access and try again.'];
        }

        $liveness = $this->prembly->verifyFaceLiveness($image);
        if (!$this->premblyOk($liveness) || !$this->livenessPassed($liveness['data'])) {
            return ['ok' => false, 'error' => 'Liveness check failed. Face the camera in good light and try again.'];
        }

        $parsed = $this->fetchBvnRecord($bvn);
        if (!$parsed['ok']) {
            return ['ok' => false, 'error' => $parsed['error'] ?? 'BVN verification failed.'];
        }

        $bvnName = (string) ($parsed['full_name'] ?? '');
        if ($bvnName === '' || !$this->names->matches($tier1Name, $bvnName)) {
            return ['ok' => false, 'error' => 'BVN name does not match the name verified at Tier 1. Contact support@usesendra.com if this is unexpected.'];
        }

        $recordPhone = (string) ($parsed['phone'] ?? '');
        if ($recordPhone === '' || !$this->phones->matches($phone, $recordPhone)) {
            return ['ok' => false, 'error' => $this->phones->mismatchMessage()];
        }

        $bvnDigits = (string) ($parsed['bvn_digits'] ?? '');

        $userModel->update($phone, $this->filterExistingColumns($userModel, array_merge([
            'liveness_verified' => 1,
            'kyc_tier' => SendraKycService::TIER_NIN,
            'kyc_verified_at' => date('Y-m-d H:i:s'),
        ], $this->verifiedIdentity('bvn', $bvnDigits, $bvnName, $recordPhone))));

        $this->notifyTierApproved($whatsapp, $phone, SendraKycService::TIER_NIN);
        $this->finishKycChatFlow($userModel, $whatsapp, $phone);

        return ['ok' => true, 'tier' => SendraKycService::TIER_NIN];
    }

    /**
     * Legacy Tier 2 for users who completed BVN-first Tier 1 before the order swap.
     *
     * @param array<string, mixed> $user
     * @return array{ok: bool, error?: string, tier?: int}
     */
    private function completeTier2WithNinLegacy(
        User $userModel,
        WhatsAppService $whatsapp,
        string $phone,
        array $user,
        string $nin,
        string $imageBase64,
    ): array {
        $digits = preg_replace('/\D+/', '', $nin) ?? '';
        if (strlen($digits) !== 11) {
            return ['ok' => false, 'error' => 'NIN must be 11 digits.'];
        }

        if (!$this->prembly->isConfigured()) {
            return ['ok' => false, 'error' => 'Verification is temporarily unavailable. Try again later.'];
        }

        $image = $this->normalizeImage($imageBase64);
        if ($image === '') {
            return ['ok' => false, 'error' => 'Live photo capture failed. Allow camera access and try again.'];
        }

        $liveness = $this->prembly->verifyFaceLiveness($image);
        if (!$this->premblyOk($liveness) || !$this->livenessPassed($liveness['data'])) {
            return ['ok' => false, 'error' => 'Liveness check failed. Face the camera in good light and try again.'];
        }

        $ninFace = $this->prembly->verifyNinWithFace($digits, $image);
        if (!$this->premblyOk($ninFace)) {
            return ['ok' => false, 'error' => $ninFace['error'] ?? 'NIN and face could not be verified. Try again.'];
        }

        $faceData = is_array($ninFace['data']['face_data'] ?? null) ? $ninFace['data']['face_data'] : [];
        if (empty($faceData['status'])) {
            return ['ok' => false, 'error' => 'Your face did not match the NIN photo. Try again with a clear, front-facing photo.'];
        }

        $ninData = is_array($ninFace['data']['nin_data'] ?? null) ? $ninFace['data']['nin_data'] : [];
        $recordPhone = (string) ($ninData['telephoneno'] ?? $ninData['phone'] ?? '');
        if ($recordPhone === '' || !$this->phones->matches($phone, $recordPhone)) {
            return ['ok' => false, 'error' => $this->phones->mismatchMessage()];
        }

        $tier1Name = trim((string) ($user['kyc_full_name'] ?? ''));
        if ($tier1Name !== '') {
            $ninName = trim(((string) ($ninData['firstname'] ?? '')) . ' ' . ((string) ($ninData['surname'] ?? '')));
            if ($ninName !== '' && !$this->names->matches($tier1Name, $ninName)) {
                return ['ok' => false, 'error' => 'NIN name does not match the name verified at Tier 1. Contact support@usesendra.com if this is unexpected.'];
            }
        }

        $ninName = trim(((string) ($ninData['firstname'] ?? '')) . ' ' . ((string) ($ninData['middlename'] ?? $ninData['middleName'] ?? '')) . ' ' . ((string) ($ninData['surname'] ?? '')));
        $ninName = trim(preg_replace('/\s+/', ' ', $ninName) ?? $ninName);

        $userModel->update($phone, $this->filterExistingColumns($userModel, array_merge([
            'liveness_verified' => 1,
            'kyc_tier' => SendraKycService::TIER_NIN,
            'kyc_verified_at' => date('Y-m-d H:i:s'),
        ], $this->verifiedIdentity('nin', $digits, $ninName, $recordPhone))));

        $this->notifyTierApproved($whatsapp, $phone, SendraKycService::TIER_NIN);
        $this->finishKycChatFlow($userModel, $whatsapp, $phone);

        return ['ok' => true, 'tier' => SendraKycService::TIER_NIN];
    }

    /**
     * @param array<string, string> $address
     * @param array{tmp_name: string, name: string, size: int, type: string}|null $proofFile
     * @return array{ok: bool, error?: string, tier?: int}
     */
    public function completeTier3(
        User $userModel,
        WhatsAppService $whatsapp,
        string $phone,
        array $address,
        ?array $proofFile = null,
    ): array {
        $user = $userModel->findByPhone($phone);
        if (!is_array($user)) {
            return ['ok' => false, 'error' => 'Account not found.'];
        }

        if ($this->kyc->getUserTier($user) < SendraKycService::TIER_NIN) {
            return ['ok' => false, 'error' => 'Complete Tier 2 verification first.'];
        }

        $proofType = strtolower(trim((string) ($address['proof_type'] ?? '')));
        if (!in_array($proofType, ['utility_bill', 'bank_statement'], true)) {
            return ['ok' => false, 'error' => 'Select utility bill or bank statement as your proof of address.'];
        }

        $proofCheck = $this->validateDocumentUpload(
            $proofFile,
            'Upload a utility bill or bank statement showing your address.',
            'Upload a PDF, JPG, or PNG utility bill or bank statement.'
        );
        if (!$proofCheck['ok']) {
            return ['ok' => false, 'error' => $proofCheck['error'] ?? 'Invalid proof document.'];
        }

        $fullName = trim((string) ($user['kyc_full_name'] ?? ''));
        $parts = $fullName !== '' ? preg_split('/\s+/', $fullName, 2) : [];
        $first = trim((string) ($address['first_name'] ?? ($parts[0] ?? '')));
        $last = trim((string) ($address['last_name'] ?? ($parts[1] ?? '')));
        $street = trim((string) ($address['address'] ?? ''));
        $state = trim((string) ($address['state'] ?? ''));
        $lga = trim((string) ($address['lga'] ?? ''));
        $houseNo = trim((string) ($address['house_no'] ?? ''));

        if ($street === '' || $state === '' || $lga === '' || $houseNo === '') {
            return ['ok' => false, 'error' => 'Enter your full address — house number, street, LGA, and state.'];
        }

        if (!$this->prembly->isConfigured()) {
            return ['ok' => false, 'error' => 'Verification is temporarily unavailable. Try again later.'];
        }

        $fullAddressLine = trim($houseNo . ' ' . $street . ', ' . $lga . ', ' . $state);

        $body = [
            'first_name' => $first !== '' ? $first : 'Customer',
            'last_name' => $last !== '' ? $last : 'User',
            'phone' => '+' . ltrim($phone, '+'),
            'address' => $fullAddressLine,
            'street' => $street,
            'state' => strtolower($state),
            'lga' => strtolower($lga),
            'house_no' => $houseNo,
            'verification_type' => 'PERSON',
        ];

        $res = $this->prembly->verifyAddress($body);
        if (!$this->premblyOk($res)) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Address verification could not be submitted. Check your details and try again.'];
        }

        $addressPayload = json_encode([
            'house_no' => $houseNo,
            'street' => $street,
            'lga' => $lga,
            'state' => $state,
            'full_line' => $fullAddressLine,
            'proof_type' => $proofType,
            'proof_sha256' => $proofCheck['sha256'] ?? null,
            'verified_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE);

        if ($addressPayload === false) {
            return ['ok' => false, 'error' => 'Could not save address. Try again.'];
        }

        try {
            $encryptedAddress = $this->crypto->encrypt($addressPayload);
        } catch (\Throwable $e) {
            error_log('KYC address encryption failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'Could not secure your address. Try again later.'];
        }

        $userModel->update($phone, [
            'address_verified' => 1,
            'smiled_verified' => 1,
            'kyc_tier' => SendraKycService::TIER_ADDRESS,
            'kyc_address_encrypted' => $encryptedAddress,
            'kyc_verified_at' => date('Y-m-d H:i:s'),
        ]);

        $this->notifyTierApproved($whatsapp, $phone, SendraKycService::TIER_ADDRESS);
        $this->finishKycChatFlow($userModel, $whatsapp, $phone);

        return ['ok' => true, 'tier' => SendraKycService::TIER_ADDRESS];
    }

    public function notifyTierApproved(WhatsAppService $whatsapp, string $phone, int $tier): void
    {
        $whatsapp->sendMessage($phone, $this->kyc->tierApprovedMessage($tier));
    }

    /**
     * @return array{ok: bool, error?: string, full_name?: string, phone?: string, bvn_digits?: string}
     */
    private function fetchBvnRecord(string $bvn): array
    {
        $digits = preg_replace('/\D+/', '', $bvn) ?? '';
        if (strlen($digits) !== 11) {
            return ['ok' => false, 'error' => 'BVN must be 11 digits.'];
        }

        if (!$this->prembly->isConfigured()) {
            return ['ok' => false, 'error' => 'Verification is temporarily unavailable. Try again later.'];
        }

        $res = $this->prembly->verifyBvnBasic($digits);
        if (!$res['success']) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Could not verify BVN. Check the number and try again.'];
        }

        $data = $res['data'];
        if (empty($data['status']) && ($data['response_code'] ?? '') !== '00') {
            return ['ok' => false, 'error' => (string) ($data['detail'] ?? 'BVN verification failed.')];
        }

        $holder = is_array($data['data'] ?? null) ? $data['data'] : [];
        $parts = array_filter([
            trim((string) ($holder['firstName'] ?? '')),
            trim((string) ($holder['middleName'] ?? '')),
            trim((string) ($holder['lastName'] ?? '')),
        ]);
        $fullName = trim(implode(' ', $parts));
        if ($fullName === '') {
            return ['ok' => false, 'error' => 'BVN verified but name could not be read. Contact support.'];
        }

        $recordPhone = trim((string) ($holder['phoneNumber'] ?? $holder['phone'] ?? ''));

        return [
            'ok' => true,
            'full_name' => $fullName,
            'phone' => $recordPhone,
            'bvn_digits' => $digits,
        ];
    }

    /**
     * @return array{ok: bool, error?: string, full_name?: string, phone?: string, nin_digits?: string}
     */
    private function fetchNinRecord(string $nin): array
    {
        $digits = preg_replace('/\D+/', '', $nin) ?? '';
        if (strlen($digits) !== 11) {
            return ['ok' => false, 'error' => 'NIN must be 11 digits.'];
        }

        if (!$this->prembly->isConfigured()) {
            return ['ok' => false, 'error' => 'Verification is temporarily unavailable. Try again later.'];
        }

        $res = $this->prembly->verifyNin($digits);
        if (!$res['success']) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Could not verify NIN. Check the number and try again.'];
        }

        $data = $res['data'];
        if (empty($data['status']) && ($data['response_code'] ?? '') !== '00') {
            return ['ok' => false, 'error' => (string) ($data['detail'] ?? 'NIN verification failed.')];
        }

        $ninData = is_array($data['nin_data'] ?? null) ? $data['nin_data'] : (
            is_array($data['Nin_data'] ?? null) ? $data['Nin_data'] : []
        );
        $parts = array_filter([
            trim((string) ($ninData['firstname'] ?? '')),
            trim((string) ($ninData['middlename'] ?? $ninData['middleName'] ?? '')),
            trim((string) ($ninData['surname'] ?? '')),
        ]);
        $fullName = trim(implode(' ', $parts));
        if ($fullName === '') {
            return ['ok' => false, 'error' => 'NIN verified but name could not be read. Contact support.'];
        }

        $recordPhone = trim((string) ($ninData['telephoneno'] ?? $ninData['phone'] ?? ''));

        return [
            'ok' => true,
            'full_name' => $fullName,
            'phone' => $recordPhone,
            'nin_digits' => $digits,
        ];
    }

    /**
     * @param array{tmp_name: string, name: string, size: int, type: string}|null $file
     * @return array{ok: bool, error?: string, sha256?: string, ext?: string}
     */
    private function validateDocumentUpload(?array $file, string $missingMessage, string $typeMessage): array
    {
        if ($file === null || ($file['tmp_name'] ?? '') === '' || !is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'error' => $missingMessage];
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'Proof document must be under 5 MB.'];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: '';
        $allowed = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/jpg' => 'jpg',
        ];
        if (!isset($allowed[$mime])) {
            return ['ok' => false, 'error' => $typeMessage];
        }

        $hash = hash_file('sha256', $file['tmp_name']);
        if ($hash === false) {
            return ['ok' => false, 'error' => 'Could not read proof document. Try again.'];
        }

        return ['ok' => true, 'sha256' => $hash, 'ext' => $allowed[$mime]];
    }

    /**
     * @param array{tmp_name: string, name: string, size: int, type: string}|null $file
     */
    private function storeSourceOfWealthProof(?array $file, string $ext): ?string
    {
        if ($file === null || ($file['tmp_name'] ?? '') === '') {
            return null;
        }

        $dir = dirname(__DIR__, 2) . '/var/kyc-sow';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            return null;
        }

        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            return null;
        }

        return $name;
    }

    private function ensureSourceOfWealthColumns(User $userModel): void
    {
        if (!$this->userColumnExists($userModel, 'kyc_source_of_wealth')) {
            $this->userDb($userModel)->exec(
                'ALTER TABLE users ADD COLUMN kyc_source_of_wealth VARCHAR(40) DEFAULT NULL'
            );
            $this->columnCache = [];
        }
        if (!$this->userColumnExists($userModel, 'kyc_sow_proof_file')) {
            $this->userDb($userModel)->exec(
                'ALTER TABLE users ADD COLUMN kyc_sow_proof_file VARCHAR(80) DEFAULT NULL'
            );
            $this->columnCache = [];
        }
    }

    private function userDb(User $userModel): \PDO
    {
        $ref = new \ReflectionClass($userModel);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        /** @var \PDO $db */
        $db = $prop->getValue($userModel);

        return $db;
    }

    private function last4(string $digits): ?string
    {
        $digits = preg_replace('/\D+/', '', $digits) ?? '';

        return strlen($digits) >= 4 ? substr($digits, -4) : null;
    }

    /**
     * Persist the verified name and phone, plus the last four digits of the NIN
     * or BVN. The full number is never stored.
     *
     * @return array<string, mixed>
     */
    private function verifiedIdentity(string $kind, string $digits, string $apiName, string $recordPhone): array
    {
        $last = $this->last4($digits);
        $apiName = trim($apiName);
        $recordPhone = trim($recordPhone);

        if ($kind === 'bvn') {
            return [
                'bvn' => null,
                'bvn_last4' => $last,
                'kyc_bvn_name' => $apiName !== '' ? $apiName : null,
                'kyc_bvn_phone' => $recordPhone !== '' ? $recordPhone : null,
            ];
        }

        return [
            'nin' => null,
            'nin_last4' => $last,
            'kyc_nin_name' => $apiName !== '' ? $apiName : null,
            'kyc_nin_phone' => $recordPhone !== '' ? $recordPhone : null,
        ];
    }

    /**
     * @param array<string, mixed> $update
     * @return array<string, mixed>
     */
    private function filterExistingColumns(User $userModel, array $update): array
    {
        $out = [];
        foreach ($update as $key => $value) {
            if ($key === 'nin' || $key === 'bvn' || $this->userColumnExists($userModel, $key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @var array<string, bool> */
    private array $columnCache = [];

    private function userColumnExists(User $userModel, string $column): bool
    {
        if (array_key_exists($column, $this->columnCache)) {
            return $this->columnCache[$column];
        }
        try {
            $stmt = $this->userDb($userModel)->prepare('SHOW COLUMNS FROM users LIKE :col');
            $stmt->execute(['col' => $column]);
            $this->columnCache[$column] = $stmt->fetch() !== false;
        } catch (\Throwable $e) {
            $this->columnCache[$column] = false;
        }

        return $this->columnCache[$column];
    }

    /**
     * @param array<string, mixed> $premblyResult
     */
    private function premblyOk(array $premblyResult): bool
    {
        if (!$premblyResult['success']) {
            return false;
        }
        $data = $premblyResult['data'];

        return !empty($data['status']) || ($data['response_code'] ?? '') === '00';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function livenessPassed(array $data): bool
    {
        if (!empty($data['status'])) {
            $verification = is_array($data['verification'] ?? null) ? $data['verification'] : [];
            $status = strtoupper((string) ($verification['status'] ?? ''));

            return $status === '' || $status === 'VERIFIED';
        }

        return false;
    }

    private function normalizeImage(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (str_starts_with($raw, 'data:image')) {
            $parts = explode(',', $raw, 2);

            return trim($parts[1] ?? '');
        }

        return $raw;
    }

    /**
     * After a tier upgrade, leave the KYC WhatsApp step — resume the blocked flow or clear it.
     *
     * @return bool true when chat_context was a KYC flow and was cleared or resumed
     */
    public function finishKycChatFlow(User $userModel, WhatsAppService $whatsapp, string $phone): bool
    {
        $user = $userModel->findByPhone($phone);
        if (!is_array($user)) {
            return false;
        }

        $raw = $user['chat_context'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return false;
        }

        $ctx = json_decode($raw, true);
        if (!is_array($ctx) || ($ctx['flow'] ?? '') !== 'kyc') {
            return false;
        }

        $resume = is_array($ctx['resume'] ?? null) ? $ctx['resume'] : null;
        $requested = (float) ($ctx['requested_ngn'] ?? 0);

        if ($resume !== null && $requested > 0 && $this->kyc->canTransactNgn($user, $requested)) {
            $userModel->update($phone, ['chat_context' => json_encode($resume, JSON_UNESCAPED_UNICODE)]);
            $whatsapp->sendMessage(
                $phone,
                'Your verification is complete. You can continue your transaction — send your last message again or type *menu*.'
            );

            return true;
        }

        $userModel->update($phone, ['chat_context' => null]);

        if ($resume !== null && $requested > 0 && !$this->kyc->canTransactNgn($user, $requested)) {
            $needed = $this->kyc->requiredTierForAmount($requested);
            $whatsapp->sendMessage(
                $phone,
                'Your tier was upgraded, but that amount still requires *'
                . $this->kyc->tierLabel($needed)
                . '*. Try a smaller amount or complete the next verification step from *menu*.'
            );
        }

        return true;
    }
}
