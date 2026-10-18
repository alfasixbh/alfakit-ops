<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Support;

/**
 * Hides the values of sensitive keys (`password`, `api_token`, `secret`…) in job payloads
 * before they reach the browser. Keys are compared word by word: `token` matches
 * `api_token`, `apiToken`, `X-Token` and `token`, but not `tokenizer`.
 */
final class Redactor
{
    /** @var list<list<string>> */
    private array $patterns;

    /**
     * @param  array<string>  $keys
     */
    public function __construct(array $keys)
    {
        $this->patterns = array_values(array_filter(array_map(self::words(...), $keys), static fn (array $words): bool => $words !== []));
    }

    public static function placeholder(): string
    {
        return (string) __('alfakit-ops::ops.failed_jobs.redacted');
    }

    public function isSensitive(int|string $key): bool
    {
        if (is_int($key)) {
            return false;
        }

        $words = self::words($key);
        $count = count($words);

        foreach ($this->patterns as $pattern) {
            $length = count($pattern);

            for ($i = 0; $i + $length <= $count; $i++) {
                if (array_slice($words, $i, $length) === $pattern) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The value with every sensitive key's value replaced, recursively.
     */
    public function redact(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];

        foreach ($value as $key => $item) {
            $redacted[$key] = $key !== SerializedValue::CLASS_KEY && $this->isSensitive($key) && $item !== null && $item !== ''
                ? self::placeholder()
                : $this->redact($item);
        }

        return $redacted;
    }

    /**
     * Whether any sensitive key appears anywhere in a raw string (used when a value can't
     * be parsed: it is hidden altogether rather than shown unredacted).
     */
    public function mentionsSensitiveKey(string $raw): bool
    {
        preg_match_all('/[A-Za-z][A-Za-z0-9_\-]*/', $raw, $matches);

        foreach (array_unique($matches[0]) as $word) {
            if ($this->isSensitive($word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `apiToken`, `api_token`, `API-TOKEN` → `['api', 'token']`.
     *
     * @return list<string>
     */
    public static function words(string $key): array
    {
        $key = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key);
        $parts = preg_split('/[^A-Za-z0-9]+/', strtolower($key)) ?: [];

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }
}
