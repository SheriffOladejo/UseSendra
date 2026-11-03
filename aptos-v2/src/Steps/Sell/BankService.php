<?php

declare(strict_types=1);

namespace AptosV2\Steps\Sell;

use App\Services\SendraCentiivRampService;
use App\Services\WhatsAppService;

/**
 * Centiiv bank list lookup + account verification.
 */
final class BankService
{
    public function __construct(private WhatsAppService $whatsapp)
    {
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public function verifyAndPopulate(
        string $from,
        array &$ctx,
        string $accountNumber,
        string $bankNameGuess,
        SendraCentiivRampService $agg
    ): bool {
        $fiat = strtoupper((string) ($ctx['fiat'] ?? 'NGN'));
        $banksRes = $agg->getBanks();
        if (!$banksRes['success']) {
            $this->whatsapp->sendMessage($from, '❌ Could not load bank list. Try again shortly.');

            return false;
        }
        $banks = SendraCentiivRampService::normalizeBankList($banksRes['data']);
        if ($banks === []) {
            error_log('Centiiv getBanks returned no parseable banks: ' . json_encode($banksRes['data']));
            $this->whatsapp->sendMessage($from, '❌ Bank list unavailable. Please try again later.');

            return false;
        }

        $bankGuessLower = strtolower($bankNameGuess);
        $isMpesa = str_contains($bankGuessLower, 'mpesa')
            || str_contains($bankGuessLower, 'm-pesa')
            || str_starts_with($accountNumber, '254')
            || (str_starts_with($accountNumber, '07') && (empty($bankNameGuess) || str_contains($bankGuessLower, 'pesa')));

        $isXofMoMo = $fiat === 'XOF'
            || str_starts_with($accountNumber, '229')
            || str_starts_with($accountNumber, '228')
            || str_contains($bankGuessLower, 'momo')
            || str_contains($bankGuessLower, 'cfa');

        if ($isMpesa) {
            $code = 'MPESA';
            $ctx['fiat'] = 'KES';
            $fiat = 'KES';
        } elseif ($isXofMoMo) {
            $ctx['fiat'] = 'XOF';
            $fiat = 'XOF';
            $netGuess = strtoupper(trim((string) ($ctx['bank_code'] ?? $bankNameGuess)));
            if (str_contains($bankGuessLower, 'tmoney') || str_contains($bankGuessLower, 't-money') || str_contains($bankGuessLower, 'togocom')) {
                $code = 'TMONEY';
            } elseif (str_contains($bankGuessLower, 'moov') || str_contains($bankGuessLower, 'flooz')) {
                $code = 'MOOV';
            } elseif (str_contains($bankGuessLower, 'mixx') || str_contains($bankGuessLower, 'miix') || str_contains($bankGuessLower, 'yas')) {
                $code = 'MIXX';
            } elseif (str_contains($bankGuessLower, 'mtn')) {
                $code = 'MTN';
            } elseif (str_contains($bankGuessLower, 'orange')) {
                $code = 'ORANGE';
            } elseif (str_contains($bankGuessLower, 'wave')) {
                $code = 'WAVE';
            } elseif (in_array($netGuess, ['TMONEY', 'MOOV', 'MIXX', 'MIIX', 'YAS', 'MTN', 'ORANGE', 'WAVE'], true)) {
                $code = $netGuess === 'YAS' ? 'MIXX' : $netGuess;
            } else {
                $code = 'TMONEY';
            }
        } else {
            $code = SendraCentiivRampService::resolveBankCode($bankNameGuess, $banks);
        }

        if ($code === null) {
            if ($fiat === 'KES' || $isMpesa) {
                $code = 'MPESA';
                $ctx['fiat'] = 'KES';
                $fiat = 'KES';
            } elseif ($fiat === 'XOF' || $isXofMoMo) {
                $code = 'TMONEY';
                $ctx['fiat'] = 'XOF';
                $fiat = 'XOF';
            } else {
                $this->whatsapp->sendMessage(
                    $from,
                    SendraCentiivRampService::bankNameUnresolvedUserMessage($bankNameGuess, $fiat)
                );

                return false;
            }
        }

        $rawBankRow = SendraCentiivRampService::findRawBankRowInBanksResponse($banksRes['data'], $code);
        if ($rawBankRow !== null) {
            $ctx['centiiv_banks_raw_row'] = $rawBankRow;
        }

        $vdata = null;
        $acctName = null;

        // Centiiv bank account lookup is only supported for Nigerian (NGN) bank accounts.
        // For M-Pesa / KES or XOF MoMo payouts, bypass lookup API.
        if ($fiat === 'NGN' && $code !== 'MPESA' && !$isXofMoMo) {
            $this->whatsapp->sendMessage($from, '⏳ Checking your account…');
            $verify = $agg->verifyBankAccount($code, $accountNumber);
            if ($verify['success']) {
                $vdata = $verify['data'];
                $acctName = $this->pickAccountName(is_array($vdata) ? $vdata : []);
            }
        }

        if ($acctName === null || $acctName === '') {
            if ($code === 'MPESA' || $fiat === 'KES') {
                $acctName = 'M-Pesa User (' . $accountNumber . ')';
                $vdata = ['accountName' => $acctName, 'bankName' => $bankNameGuess !== '' ? $bankNameGuess : 'M-Pesa'];
            } elseif ($isXofMoMo || $fiat === 'XOF') {
                $netName = match ($code) {
                    'TMONEY' => 'TMoney',
                    'MOOV' => 'Moov',
                    'MIXX', 'YAS' => 'Mixx by Yas',
                    'MTN' => 'MTN',
                    default => ucfirst(strtolower($code)),
                };
                $acctName = 'MoMo User (' . $accountNumber . ')';
                $vdata = ['accountName' => $acctName, 'bankName' => $netName . ' MoMo'];
            } else {
                $err = $verify['error'] ?? 'Verify failed';
                error_log('Bank verify failed for ' . $from . ': ' . $err);
                $this->whatsapp->sendMessage(
                    $from,
                    SendraCentiivRampService::bankVerifyFailureUserMessage(
                        $err,
                        $bankNameGuess,
                        $accountNumber,
                        $fiat
                    )
                );

                return false;
            }
        }

        $userResolvedCode = SendraCentiivRampService::normalizeCentiivBankCode($code);
        $resolved = SendraCentiivRampService::resolvedBankFromVerifyEnvelope(
            is_array($vdata) ? $vdata : []
        );
        if ($resolved !== null && $resolved['code'] !== '') {
            if ($resolved['code'] !== $userResolvedCode) {
                error_log(
                    'Sendra sell v2: verify bank differs from user guess; using resolved bankCode. '
                    . json_encode(['wa' => $from, 'guess' => $bankNameGuess, 'user_code' => $userResolvedCode, 'resolved' => $resolved])
                );
            }
            $code = $resolved['code'];
            $bankDisplay = !empty($resolved['name'])
                ? (string) $resolved['name']
                : $this->pickBankLabel(is_array($vdata) ? $vdata : [], $banks, $code, $bankNameGuess);
        } else {
            $bankDisplay = $this->pickBankLabel(is_array($vdata) ? $vdata : [], $banks, $code, $bankNameGuess);
        }

        $ctx['account_number'] = $accountNumber;
        $ctx['bank_code'] = $code;
        $ctx['bank_name'] = $bankDisplay;
        $ctx['account_name'] = $acctName;
        $ctx['centiiv_verify_data'] = is_array($vdata) ? $vdata : null;
        $ctx['preset_account'] = $accountNumber;
        $ctx['preset_bank_guess'] = $bankDisplay;

        return true;
    }

    /**
     * @param array<string, mixed> $vdata
     */
    private function pickAccountName(array $vdata): ?string
    {
        $d = $vdata['data'] ?? $vdata;
        if (!is_array($d)) {
            $d = $vdata;
        }
        foreach (['accountName', 'account_name', 'accountHolderName', 'name'] as $k) {
            if (!empty($d[$k]) && is_string($d[$k])) {
                return $d[$k];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $vdata
     * @param list<array{code: string, name: string}> $banks
     */
    private function pickBankLabel(array $vdata, array $banks, string $code, string $fallbackGuess): string
    {
        $d = $vdata['data'] ?? $vdata;
        if (is_array($d)) {
            foreach (['bankName', 'bank_name'] as $k) {
                if (!empty($d[$k]) && is_string($d[$k])) {
                    return $d[$k];
                }
            }
            if (isset($d['bank']) && is_string($d['bank']) && $d['bank'] !== '') {
                return $d['bank'];
            }
            if (isset($d['bank']) && is_array($d['bank'])) {
                $bk = $d['bank'];
                foreach (['bankName', 'name', 'longName', 'shortName'] as $k) {
                    if (!empty($bk[$k]) && is_string($bk[$k])) {
                        return $bk[$k];
                    }
                }
            }
        }
        foreach ($banks as $b) {
            if ($b['code'] === $code) {
                return $b['name'];
            }
        }

        return $fallbackGuess;
    }
}
