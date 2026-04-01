<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;
use App\Utils\Translator;

class LicensePictureStep extends BaseStep
{
    protected string $validationError;

    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];
        $lang  = $session['language'] ?? 'en';
        $t     = new Translator($lang);

        $imageData = json_decode($message, true);

        if (!isset($imageData['image'])) {
            return $this->validationError;
        }

        $imageUrl = $imageData['image']['url'] ?? null;
        $imageId  = $imageData['image']['id'] ?? null;

        if (!$imageUrl || !$imageId) {
            return $this->validationError;
        }

        $savedPath = $this->downloadAndSaveImage($imageUrl, $imageId, $phone, 'license');

        $this->driverModel->update($phone, [
            'license_picture_url' => $savedPath
        ]);

        $this->nextStep($phone, 'plate_number');

        return $t->get('license_picture_received');
    }

    public function validateInput(string $message): bool
    {
        $t = new Translator($this->session['language'] ?? 'en');
        $this->validationError = $t->get('license_picture_prompt');
        $data = json_decode($message, true);

        if (json_last_error() !== JSON_ERROR_NONE) return false;
        if (!isset($data['image'])) return false;
        if (!isset($data['image']['url']) || !isset($data['image']['id'])) return false;

        return true;
    }

    private function downloadAndSaveImage(string $url, string $imageId, string $phone, string $type): string
    {
        $imageContent = $this->whatsappService->downloadMedia($imageId);

        $projectRoot = dirname(__DIR__, 3);
        $uploadDir   = $projectRoot . '/uploads/drivers/' . $phone;

        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $filename = $type . '_' . time() . '_' . $imageId . '.jpg';
        $filepath = $uploadDir . '/' . $filename;

        file_put_contents($filepath, $imageContent);

        return 'uploads/drivers/' . $phone . '/' . $filename;
    }
}