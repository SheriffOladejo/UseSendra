<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

class VehicleTypeStep extends BaseStep
{
    /**
     * Prompt rider to choose vehicle type
     */
    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];

        // Define allowed vehicle types
        $validTypes = [
            'motorcycle' => '🏍 *Motorcycle*',
            'keke'   => '🛺 *Keke*'
        ];

        // Normalize message
        $msg = strtolower(trim($message));
        $msg = ltrim($msg, '/'); // allow /motorcycle style input

        if (!array_key_exists($msg, $validTypes)) {
            $this->whatsappService->sendButtonMessage(
                $phone,
                "Sorry, that's not a valid vehicle type. Please choose one of the following:",
                [
                    "motorcycle" => "🏍️ Motorcycle",
                    "keke"  => "🛺 Keke"
                ]
            );
            return '';
        }

        // Save chosen vehicle type
        $this->driverModel->update($phone, [
            'vehicle_type' => $msg
        ]);

        // Move to next step
        $this->nextStep($phone, 'nin');

        return "Excellent! 🎯 .\n\nTo keep things safe for everyone, we’ll need to verify your details, this should take less than 2 minutes. First enter your NIN ";

   }

    /**
     * Only accept input if it's one of the allowed vehicle types
     */
    public function validateInput(string $message): bool
    {

        $validTypes = [
            'motorcycle' => '🏍 *Motorcycle*',
            'keke'   => '🛺 *Keke*'
        ];
        $validKeys = ['motorcycle', 'keke'];

        if (!in_array(strtolower(trim($message)), $validKeys)) {
            return "Sorry, that's not a valid vehicle type. Please choose one of the following:\n\n" .
                   implode("\n", array_map(fn($label, $key) => "Type $key for $label", $validTypes, array_keys($validTypes)));
        }

        $msg = strtolower(trim($message));
        $msg = ltrim($msg, '/');

        return in_array($msg, $validKeys);
    }
}