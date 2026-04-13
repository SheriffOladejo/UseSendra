<?php

namespace App\Steps;

use App\Steps\BaseStep;
use App\Utils\Translator;

class StartStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $name = $this->getUsername() ?? 'there';

        $driver = $this->driverModel->findByPhone($phone);
        if ($driver) {
            $status = $driver['verification_status'] ?? 'pending';
            if (in_array($status, ['approved', 'approved_test', 'approved_paused'], true)) {
                $this->nextStep($phone, 'rider_ready');
                $fn = trim((string) ($driver['first_name'] ?? ''));
                $greet = 'Sendra Logistics 🚚' . "\nHi " . ($fn !== '' ? $fn : $name) . ' — ';
                if ($status === 'approved_paused') {
                    $greet .= "your rider account is approved but *live job alerts are paused* (testing/ops). You can still use the chat; you'll be re-enabled for broadcasts when we're ready.";
                } elseif ($status === 'approved_test') {
                    $greet .= "you're a *test rider*. You only get job pings when the server is in test-courier mode — normal riders are not notified.";
                } else {
                    $greet .= "you're on as a rider. You'll get alerts for nearby jobs.";
                }
                $this->whatsappService->sendMessage($phone, $greet);
                return '';
            }
            if ($status === 'rejected') {
                $this->nextStep($phone, 'driver_rejected');
                $this->whatsappService->sendMessage(
                    $phone,
                    'Sendra Logistics 🚚' . "\nYour rider application needs updates. Follow the prompts to resubmit."
                );
                return '';
            }
            $this->nextStep($phone, 'in_review');
            $this->whatsappService->sendMessage(
                $phone,
                'Sendra Logistics 🚚' . "\nYour rider profile is still under review. We'll message you when it's approved."
            );
            return '';
        }

        $user = $this->userModel->findByPhone($phone);
        if ($user) {
            $this->nextStep($phone, 'sender_menu');
            $disp = $user['whatsapp_name'] ?? $name;
            $this->whatsappService->sendMessage(
                $phone,
                "Sendra Logistics 📦\nHi {$disp} — what would you like to do?\n\n"
                . "• Type *send* to ship a package\n"
                . "• *order* to track\n"
                . "• *help* for support\n"
                . "• *history* for past orders\n"
                . "• *restart* if you're stuck on an old offer or search\n"
                . "• *track* during an active delivery to ping your rider for a map link"
            );
            return '';
        }

        $this->nextStep($phone, 'choose_role');
        $translator = new Translator('en');
        $this->whatsappService->sendButtonMessage(
            $phone,
            $translator->get('choose_role_prompt', ['name' => $name]),
            [
                'sender' => $translator->get('role_sender'),
                'rider' => $translator->get('role_rider'),
            ]
        );

        return '';
    }

    public function validateInput($message): bool
    {
        return true;
        // Accept any greeting or non-empty text
        //$greetings = ['hi', 'hello', 'hey', 'hola', 'howdy'];
        //$text = strtolower(trim($message));

        //return !empty($text) && (in_array($text, $greetings) || strlen($text) > 1);
    }
}