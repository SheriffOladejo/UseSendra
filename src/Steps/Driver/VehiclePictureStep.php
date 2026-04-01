<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;
use App\Utils\Translator;

class VehiclePictureStep extends BaseStep
{
    protected string $validationError;

    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];
        $lang  = $session['language'] ?? 'en';
        $t     = new Translator($lang);

        // $message should contain the WhatsApp image data
        $imageData = json_decode($message, true);

        if (!isset($imageData['image'])) {
            return $this->validationError;
        }

        $imageUrl = $imageData['image']['url'] ?? null;
        $imageId  = $imageData['image']['id'] ?? null;

        if (!$imageUrl || !$imageId) {
            return $this->validationError;
        }

        // Download and store the image
        $savedPath = $this->downloadAndSaveImage($imageUrl, $imageId, $phone, 'vehicle');

        $this->driverModel->update($phone, [
            'vehicle_picture_url' => $savedPath
        ]);

        $this->nextStep($phone, 'vehicle_color');

        return $t->get('vehicle_picture_received');
    }

    public function validateInput(string $message): bool
    {

        $t = new Translator($this->session['language'] ?? 'en');
        $this->validationError = $t->get('vehicle_picture_prompt');
        $data = json_decode($message, true);

        if (json_last_error() !== JSON_ERROR_NONE) return false;
        if (!isset($data['image'])) return false;
        if (!isset($data['image']['url']) || !isset($data['image']['id'])) return false;

        return true;
    }

    private function downloadAndSaveImage(string $url, string $imageId, string $phone, string $type): string
    {
        $imageContent = $this->whatsappService->downloadMedia($imageId);
        $projectRoot  = dirname(__DIR__, 3);
        $uploadDir    = $projectRoot . '/uploads/drivers/' . $phone;

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            error_log("Failed to create upload directory: $uploadDir");
            throw new \Exception("Failed to create upload directory");
        }

        $timestamp  = time();
        $filename   = $type . '_' . $timestamp . '_' . $imageId . '.jpg';
        $filepath   = $uploadDir . '/' . $filename;

        if (file_put_contents($filepath, $imageContent) === false) {
            error_log("Failed to save image to: $filepath");
            throw new \Exception("Failed to save image file");
        }

        error_log("Image saved successfully: $filepath");

        return 'uploads/drivers/' . $phone . '/' . $filename;
    }
}