<?php

namespace App\Controllers;

use App\Models\LogisticsUser;
use App\Services\WhatsAppService;

class LogisticsAuthController
{
    private $userModel;
    private $whatsappService;

    public function __construct($db, WhatsAppService $whatsappService)
    {
        $this->userModel = new LogisticsUser($db);
        $this->whatsappService = $whatsappService;
    }

    /**
     * Register a logistics user (sender or driver)
     */
    public function signup($phoneNumber, $name)
    {
        // Check if phone already exists
        if ($this->userModel->exists($phoneNumber)) {
            return [
                'success' => false,
                'message' => 'Phone number already registered',
                'user' => $this->userModel->findByPhone($phoneNumber),
            ];
        }

        $userId = $this->userModel->create($phoneNumber, $name);

        if ($userId) {

            error_log("New logistics user registered: {$name} ({$phoneNumber})");

            $this->whatsappService->sendMessage(
                $phoneNumber,
                "Welcome to *Sendra Courier* 🚚\n\nYour account has been created successfully."
            );

            return [
                'success' => true,
                'message' => 'User registered successfully',
                'user_id' => $userId,
                'user' => $this->userModel->findByPhone($phoneNumber),
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to create user',
        ];
    }

    /**
     * Login by phone number
     */
    public function login($phoneNumber)
    {
        $user = $this->userModel->findByPhone($phoneNumber);

        if ($user) {
            return [
                'success' => true,
                'message' => 'User authenticated',
                'user' => $user,
            ];
        }

        return [
            'success' => false,
            'message' => 'User not found',
        ];
    }

    /**
     * Get user by phone number
     */
    public function getUser($phoneNumber)
    {
        $user = $this->userModel->findByPhone($phoneNumber);

        if ($user) {
            return [
                'success' => true,
                'exists' => true,
                'user' => $user,
            ];
        }

        return [
            'success' => false,
            'exists' => false,
            'message' => 'User not found',
        ];
    }

    /**
     * Expose model to other controllers
     */
    public function getUserModel(): LogisticsUser
    {
        return $this->userModel;
    }
}