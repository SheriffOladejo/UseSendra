<?php

namespace App\Utils;

class Translator
{
    protected string $lang;
    protected array $strings = [];

    public function __construct(string $lang = 'en')
    {
        $this->lang = $lang;

        // Use absolute path to config/Translations
        $file = __DIR__ . '/../../config/Translations/' . $lang . '.php';

        if (file_exists($file)) {
            $this->strings = require $file;
        } else {
            // fallback to English if file not found
            $this->strings = require __DIR__ . '/../../config/Translations/en.php';
        }
    }

    public function get(string $key, array $replacements = []): string
    {
        $text = $this->strings[$key] ?? $key;

        // replace placeholders like {name}
        foreach ($replacements as $k => $v) {
            $text = str_replace("{{$k}}", $v, $text);
        }

        return $text;
    }
}