<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\MarketplaceOrder;
use App\Services\WhatsAppService;

final class MarketplaceRefundNotifier
{
    public function __construct(
        private readonly MarketplaceOrder $orderModel,
        private readonly WhatsAppService $whatsapp
    ) {
    }

    public function sendToBuyer(string $phone, string $message): void
    {
        $this->whatsapp->sendMessage($phone, $message);
    }

    /**
     * @param array<string, mixed> $order
     */
    public function recordAndNotifyPartialRefund(array $order, float $amountNgn, string $reason): void
    {
        $mid = (int) $order['id'];
        $prev = (float) ($order['refund_total_ngn'] ?? 0);
        $newTotal = $prev + $amountNgn;
        $this->orderModel->update($mid, [
            'refund_total_ngn' => $newTotal,
            'payment_status' => $newTotal >= (float) ($order['amount_paid_ngn'] ?? $order['total_ngn'] ?? 0)
                ? 'refunded' : 'paid',
        ]);
        error_log("Marketplace refund M-{$mid}: ₦{$amountNgn} reason={$reason} (manual/Centiiv refund queue)");
        $this->whatsapp->sendMessage(
            (string) $order['buyer_phone'],
            '💳 Refund of ₦' . number_format($amountNgn, 0) . " for order #M-{$mid} is being processed (3–7 business days)."
        );
    }
}
