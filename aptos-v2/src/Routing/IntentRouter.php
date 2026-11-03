<?php

declare(strict_types=1);

namespace AptosV2\Routing;

/**
 * Detects "start a new flow" intents from a raw message.
 *
 * Intents are NOT steps — they are the command layer that sits in front of the
 * step router (the same separation the Logistics controller keeps between its
 * prefix checks and getStepHandler()). Each matcher is a callable returning
 * true/false; the first match wins.
 *
 * Anything not matched here is handed off to the legacy controller, which is
 * what makes the migration a safe strangler-fig: unported commands keep working.
 */
final class IntentRouter
{
    /** @var array<int, array{matcher: callable(string, string): bool, intent: IntentMatch}> */
    private array $intents = [];

    /**
     * @param array<int, array{match: callable(string, string): bool, flow: string, step: string}> $config
     */
    public function __construct(array $config)
    {
        foreach ($config as $entry) {
            $this->intents[] = [
                'matcher' => $entry['match'],
                'intent' => new IntentMatch($entry['flow'], $entry['step']),
            ];
        }
    }

    public function match(string $message, string $messageLower): ?IntentMatch
    {
        foreach ($this->intents as $entry) {
            if (($entry['matcher'])($message, $messageLower) === true) {
                return $entry['intent'];
            }
        }

        return null;
    }
}
