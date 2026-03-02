<?php

namespace App\Controllers;

use App\Models\User;
use App\Services\WhatsAppService;

class AuthController
{
    private $userModel;
    private $whatsappService;

    public function __construct($db, WhatsAppService $whatsappService)
    {
        $this->userModel      = new User($db);
        $this->whatsappService = $whatsappService;
    }

    /**
     * Register a new user.
     * Handles both sendra-native (encrypted key) and petra (no key) account types.
     *
     * @param string      $phoneNumber
     * @param string      $username
     * @param string      $aptosAddress
     * @param string|null $encryptedPrivateKey  — null for petra users
     * @param string      $accountType          — 'sendra' or 'petra'
     */
    public function signup($phoneNumber, $username, $aptosAddress, $encryptedPrivateKey = null, $accountType = 'sendra')
    {
        // Block duplicate phone numbers
        if ($this->userModel->exists($phoneNumber)) {
            return [
                'success' => false,
                'message' => 'Phone number already registered',
                'user'    => $this->userModel->findByPhone($phoneNumber),
            ];
        }

        // Block duplicate Aptos addresses
        if ($this->userModel->addressExists($aptosAddress)) {
            return [
                'success' => false,
                'message' => 'Aptos address already registered',
                'user'    => $this->userModel->findByAptosAddress($aptosAddress),
            ];
        }

        $userId = $this->userModel->create($phoneNumber, $username, $aptosAddress, $encryptedPrivateKey, $accountType);

        if ($userId) {
            error_log("New {$accountType} user registered: {$username} ({$phoneNumber})");
            return [
                'success'  => true,
                'message'  => 'User registered successfully',
                'user_id'  => $userId,
                'user'     => $this->userModel->findByPhone($phoneNumber),
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to create user',
        ];
    }

    /**
     * Authenticate an existing user by phone number.
     */
    public function login($phoneNumber)
    {
        $user = $this->userModel->findByPhone($phoneNumber);

        if ($user) {
            return [
                'success' => true,
                'message' => 'User authenticated',
                'user'    => $user,
            ];
        }

        return [
            'success' => false,
            'message' => 'User not found',
        ];
    }

    /**
     * Get a user by phone number.
     */
    public function getUser($phoneNumber)
    {
        $user = $this->userModel->findByPhone($phoneNumber);

        if ($user) {
            return [
                'success' => true,
                'user'    => $user,
                'exists'  => true,
            ];
        }

        return [
            'success' => false,
            'message' => 'User not found',
            'exists'  => false,
        ];
    }

    /**
     * Expose the User model to controllers that need it (e.g. MessageController).
     */
    public function getUserModel(): User
    {
        return $this->userModel;
    }
}