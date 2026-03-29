<?php
namespace App\Steps;

interface StepInterface
{
    /**
     * Handle the incoming message
     *
     * @param array $session Current session data
     * @param string $message Incoming message from user
     * @return string Response to send
     */
    public function handle(array $session, string $message): string;

    /**
     * Optionally validate input type
     *
     * @param string $message
     * @return bool
     */
    public function validateInput(string $message): bool;
}