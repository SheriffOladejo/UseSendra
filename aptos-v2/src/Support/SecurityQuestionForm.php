<?php

declare(strict_types=1);

namespace AptosV2\Support;

/**
 * Security-question challenge on PIN confirmation, and the separate setup form.
 * Saved answers are never rendered.
 */
final class SecurityQuestionForm
{
    /**
     * @param array{id: int, question: string, mac: string}|null $challenge
     */
    public static function renderChallenge(int $savedCount, ?array $challenge, ?string $setupUrl): string
    {
        $max = SecurityQuestionService::MAX_QUESTIONS;
        $html = '';

        if ($challenge !== null) {
            $html .= '<div class="sq">'
                . '<p class="sq-label">Security question</p>'
                . '<p class="sq-prompt">' . self::h($challenge['question']) . '</p>'
                . '<input type="hidden" name="sq_id" value="' . self::h((string) $challenge['id']) . '">'
                . '<input type="hidden" name="sq_mac" value="' . self::h($challenge['mac']) . '">'
                . '<div class="field">'
                . '<label for="security_answer">Your answer</label>'
                . self::secretField(
                    '<input class="text-input" id="security_answer" name="security_answer" type="password" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="64" required value="'
                    . self::h((string) ($_POST['security_answer'] ?? '')) . '">'
                )
                . '</div>'
                . '</div>';
        }

        if ($setupUrl !== null && $setupUrl !== '' && $savedCount < $max) {
            $label = $savedCount > 0 ? 'Add a security question' : 'Set up security questions';
            $html .= '<a class="btn btn-secondary" href="' . self::h($setupUrl) . '">' . self::h($label) . '</a>'
                . '<p class="hint">Optional. You can save up to ' . $max . ' questions'
                . ($savedCount > 0 ? ' (' . $savedCount . ' of ' . $max . ' saved)' : '')
                . ', then return here to confirm this transaction.</p>';
        }

        return $html;
    }

    /**
     * @param list<array{question: string, answer: string, confirm: string}> $rows
     */
    public static function renderSetupRows(int $savedCount, array $rows): string
    {
        $max = SecurityQuestionService::MAX_QUESTIONS;
        $remaining = max(0, $max - $savedCount);
        if ($rows === []) {
            $rows = [['question' => '', 'answer' => '', 'confirm' => '']];
        }

        $html = '<p class="hint">Save up to ' . $max . ' questions'
            . ($savedCount > 0 ? '. ' . $savedCount . ' of ' . $max . ' already saved' : '')
            . '.</p>';
        foreach (array_values($rows) as $index => $row) {
            $html .= self::rowHtml($index, $row);
        }
        $html .= '<button class="sq-add-btn" id="sq-add-another" type="button"'
            . (count($rows) >= $remaining ? ' hidden' : '')
            . ' data-remaining="' . $remaining . '">Add another question</button>';

        return $html;
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, error: ?string}
     */
    public static function prepare(
        SecurityQuestionService $service,
        string $phone,
        string $token,
        bool $requireChallenge,
        array $post
    ): array {
        if ($requireChallenge && $service->count($phone) > 0) {
            $id = (int) ($post['sq_id'] ?? 0);
            $mac = (string) ($post['sq_mac'] ?? '');
            $answer = (string) ($post['security_answer'] ?? '');
            if (trim($answer) === '') {
                return ['ok' => false, 'error' => 'Enter the answer to your security question.'];
            }
            if (!$service->verifyChallenge($phone, $token, $id, $mac, $answer)) {
                return ['ok' => false, 'error' => 'Security answer does not match.'];
            }
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, error: ?string, pending: list<array{question: string, answer: string}>}
     */
    public static function collectNew(SecurityQuestionService $service, string $phone, array $post): array
    {
        $questions = $post['sq_question'] ?? [];
        $answers = $post['sq_answer'] ?? [];
        $confirms = $post['sq_answer_confirm'] ?? [];
        if (!is_array($questions) || !is_array($answers) || !is_array($confirms)) {
            return ['ok' => false, 'error' => 'Could not read the security question. Please try again.', 'pending' => []];
        }

        $pending = [];
        $seen = [];
        $count = max(count($questions), count($answers), count($confirms));
        for ($i = 0; $i < $count; $i++) {
            $question = trim((string) ($questions[$i] ?? ''));
            $answer = (string) ($answers[$i] ?? '');
            $confirm = (string) ($confirms[$i] ?? '');
            if ($question === '' && trim($answer) === '' && trim($confirm) === '') {
                continue;
            }
            if ($question === '' || trim($answer) === '' || trim($confirm) === '') {
                return ['ok' => false, 'error' => 'Enter a security question and answer, then enter the answer again.', 'pending' => []];
            }
            if (mb_strtolower(trim($answer), 'UTF-8') !== mb_strtolower(trim($confirm), 'UTF-8')) {
                return ['ok' => false, 'error' => 'Security answers do not match. Please try again.', 'pending' => []];
            }
            $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $question) ?? ''), 'UTF-8');
            if (isset($seen[$key])) {
                return ['ok' => false, 'error' => 'Use a different question for each entry.', 'pending' => []];
            }
            $seen[$key] = true;
            $pending[] = ['question' => $question, 'answer' => $answer];
        }

        if ($pending === []) {
            return ['ok' => false, 'error' => 'Enter at least one security question and answer.', 'pending' => []];
        }

        $saved = $service->count($phone);
        if (count($pending) > max(0, SecurityQuestionService::MAX_QUESTIONS - $saved)) {
            return ['ok' => false, 'error' => 'You can save up to 5 security questions.', 'pending' => []];
        }

        return ['ok' => true, 'error' => null, 'pending' => $pending];
    }

    /**
     * @param list<array{question: string, answer: string}> $pending
     */
    public static function savePending(SecurityQuestionService $service, string $phone, array $pending): ?string
    {
        foreach ($pending as $row) {
            $saved = $service->add($phone, $row['question'], $row['answer']);
            if (empty($saved['ok'])) {
                return (string) ($saved['error'] ?? 'Could not save the security question.');
            }
        }

        return null;
    }

    /**
     * @return list<array{question: string, answer: string, confirm: string}>
     */
    public static function postedRows(): array
    {
        $questions = $_POST['sq_question'] ?? null;
        if (!is_array($questions)) {
            return [];
        }
        $answers = is_array($_POST['sq_answer'] ?? null) ? $_POST['sq_answer'] : [];
        $confirms = is_array($_POST['sq_answer_confirm'] ?? null) ? $_POST['sq_answer_confirm'] : [];
        $rows = [];
        foreach ($questions as $index => $question) {
            $rows[] = [
                'question' => (string) $question,
                'answer' => (string) ($answers[$index] ?? ''),
                'confirm' => (string) ($confirms[$index] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param array{question?: string, answer?: string, confirm?: string} $row
     */
    private static function rowHtml(int $index, array $row = []): string
    {
        return '<div class="sq-row" data-sq-row>'
            . '<div class="field">'
            . '<label>Question</label>'
            . '<input class="text-input" name="sq_question[' . $index . ']" type="text" maxlength="160" autocomplete="off" value="'
            . self::h((string) ($row['question'] ?? '')) . '">'
            . '</div>'
            . '<div class="field">'
            . '<label>Answer</label>'
            . self::secretField(
                '<input class="text-input" name="sq_answer[' . $index . ']" type="password" maxlength="64" autocomplete="off" autocapitalize="off" spellcheck="false" value="'
                . self::h((string) ($row['answer'] ?? '')) . '">'
            )
            . '</div>'
            . '<div class="field">'
            . '<label>Confirm answer</label>'
            . self::secretField(
                '<input class="text-input" name="sq_answer_confirm[' . $index . ']" type="password" maxlength="64" autocomplete="off" autocapitalize="off" spellcheck="false" value="'
                . self::h((string) ($row['confirm'] ?? '')) . '">'
            )
            . '</div>'
            . '</div>';
    }

    private static function secretField(string $inputHtml): string
    {
        return '<div class="secret-field">'
            . $inputHtml
            . '<button type="button" class="secret-toggle" aria-label="Show answer">'
            . '<svg class="icon-show" viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>'
            . '<svg class="icon-hide" viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.6 10.6A3 3 0 0 0 12 15a3 3 0 0 0 2.4-4.4M9.9 5.2A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a18.2 18.2 0 0 1-3.2 4.2M6.1 6.1C3.7 7.8 2 12 2 12a18.6 18.6 0 0 0 6.2 5.8"/></svg>'
            . '</button></div>';
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
