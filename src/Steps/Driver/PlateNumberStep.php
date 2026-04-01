<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

class PlateNumberStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {
        $plateNumber = strtoupper(str_replace(' ', '', trim($message)));

        $this->driverModel->update($session['phone_number'], [
            'vehicle_plate_number' => $plateNumber
        ]);

        $this->nextStep($session['phone_number'], 'vehicle_color');

        return "Great! 🚗  

        Now, what's the color of your vehicle?  

        Please type the color (e.g., 'Black', 'White', 'Red', 'Blue', etc.)";
    }

    public function validateInput(string $plateNumber): bool
    {
        $plateNumber = strtoupper(trim($plateNumber));

        // Match formats like ABC-123DE or ABC123DE
        if (preg_match('/^[A-Z]{3}-?\d{3}[A-Z]{2}$/', $plateNumber)) {
            return true;
        }

        $this->validationError = "Please enter a valid Nigerian vehicle plate number (e.g., ABC-123DE or ABC123DE).";
        return false;
    }
}