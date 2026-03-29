<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class ItemPictureStep extends BaseStep
{
    protected string $validationError = "📷 Please send a clear picture of the item.\nUse '+' → 'Camera' or 'Gallery'.";

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $requestId = $session['delivery_request_id'] ?? null;
        if (!$requestId) return "We couldn't find your delivery request. Please start again.";

        $imageData = json_decode($message, true);
        if (!isset($imageData['image'])) return $this->validationError;

        $imageUrl = $imageData['image']['url'] ?? null;
        $imageId = $imageData['image']['id'] ?? null;
        if (!$imageUrl || !$imageId) return $this->validationError;

        $savedPath = $this->downloadAndSaveImage($imageUrl, $imageId, $phone, 'item');

        $this->deliveryRequestModel->update($requestId, [
            'item_photo_url' => $savedPath
        ]);

        $this->nextStep($phone, 'sender_phone');

        return "Item picture received.\n\nPlease enter the sender's phone number.";
    }

    public function validateInput(string $message): bool
    {
        $data = json_decode($message, true);
        return json_last_error() === JSON_ERROR_NONE && isset($data['image']['url'], $data['image']['id']);
    }

    private function downloadAndSaveImage(string $url, string $imageId, string $phone, string $type): string
    {
        $imageContent = $this->whatsappService->downloadMedia($imageId);

        $projectRoot = dirname(__DIR__, 3);
        $uploadDir = $projectRoot . '/uploads/items/' . $phone;
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $filename = $type . '_' . time() . '_' . $imageId . '.jpg';
        $filepath = $uploadDir . '/' . $filename;

        file_put_contents($filepath, $imageContent);

        return 'uploads/items/' . $phone . '/' . $filename;
    }
}