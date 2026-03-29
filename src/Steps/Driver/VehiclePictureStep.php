<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

class VehiclePictureStep extends BaseStep
{
    protected string $validationError = "📷 We need a photo of your vehicle.

Please send a clear picture showing:
- Full vehicle (front or side view)
- Visible plate number
- Good lighting

Tap the '+' icon and select 'Camera' or 'Gallery' to upload.";

    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];
        
        // $message should contain the WhatsApp image data
        $imageData = json_decode($message, true);
        
        if (!isset($imageData['image'])) {
            return $this->validationError;
        }

        $imageUrl = $imageData['image']['url'] ?? null;
        $imageId = $imageData['image']['id'] ?? null;

        if (!$imageUrl || !$imageId) {
            return $this->validationError;
        }

        // Download and store the image
        $savedPath = $this->downloadAndSaveImage($imageUrl, $imageId, $phone, 'vehicle');

        $this->driverModel->update($phone, [
            'vehicle_picture_url' => $savedPath
        ]);

        $this->nextStep($phone, 'vehicle_color');

        return "Great!\n\n🚗 Vehicle photo received.\n\nNow, please tell us the color of your vehicle. For example: Black, Silver, Red.";
    }

    public function validateInput(string $message): bool
    {
        // Check if the message is valid JSON
        $data = json_decode($message, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        // Check if it contains image data
        if (!isset($data['image'])) {
            return false;
        }

        // Check if required fields exist
        if (!isset($data['image']['url']) || !isset($data['image']['id'])) {
            return false;
        }

        return true;
    }

    /**
     * Download image from WhatsApp and save locally
     */
    private function downloadAndSaveImage(string $url, string $imageId, string $phone, string $type): string
    {
        // Use WhatsApp service to download the image using media ID
        $imageContent = $this->whatsappService->downloadMedia($imageId);
        
        // Use project root instead of relative path
        $projectRoot = dirname(__DIR__, 3); // Goes up 3 levels from src/Steps/Driver/
        $uploadDir = $projectRoot . '/uploads/drivers/' . $phone;
        
        // Create directory if it doesn't exist
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                error_log("Failed to create upload directory: $uploadDir");
                throw new \Exception("Failed to create upload directory");
            }
        }

        // Generate filename
        $timestamp = time();
        $filename = $type . '_' . $timestamp . '_' . $imageId . '.jpg';
        $filepath = $uploadDir . '/' . $filename;

        // Save image
        if (file_put_contents($filepath, $imageContent) === false) {
            error_log("Failed to save image to: $filepath");
            throw new \Exception("Failed to save image file");
        }

        error_log("Image saved successfully: $filepath");

        // Return the relative path for database storage
        return 'uploads/drivers/' . $phone . '/' . $filename;
    }
}