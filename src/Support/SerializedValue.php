<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Support;

use UnexpectedValueException;

/**
 * Reads a PHP `serialize()` string (a queued job's `data.command`) into plain arrays
 * WITHOUT calling `unserialize()`: no class is loaded or instantiated, no magic method
 * runs. Objects become `['__class' => 'App\Jobs\SendInvoice', 'invoice' => …]`, enums
 * `'App\Enums\Status::Paid'`, references `'(reference)'`.
 *
 * Returns null for anything it doesn't fully understand, so callers can fall back to a
 * safe placeholder instead of showing raw, unredacted data.
 */
final class SerializedValue
{
    public const CLASS_KEY = '__class';

    private const MAX_DEPTH = 64;

    private int $position = 0;

    private function __construct(private readonly string $input) {}

    public static function parse(string $input): mixed
    {
        $parser = new self($input);

        try {
            $value = $parser->readValue(0);
        } catch (UnexpectedValueException) {
            return null;
        }

        // Trailing garbage: not a serialized value.
        return $parser->position === strlen($input) ? $value : null;
    }

    public static function looksSerialized(string $input): bool
    {
        return preg_match('/^(?:[Oa]:\d+:|[Cs]:\d+:"|[bi]:-?\d+;|d:[^;]+;|N;|E:\d+:")/', $input) === 1;
    }

    private function readValue(int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            $this->fail();
        }

        $type = $this->input[$this->position] ?? '';

        return match ($type) {
            'N' => $this->readNull(),
            'b' => $this->readScalar('b') === '1',
            'i' => (int) $this->readScalar('i'),
            'd' => (float) $this->readScalar('d'),
            's' => $this->readString(),
            'a' => $this->readArray($depth),
            'O' => $this->readObject($depth),
            'C' => $this->readCustomObject(),
            'E' => $this->readEnum(),
            'r', 'R' => $this->readReference(),
            default => $this->fail(),
        };
    }

    private function readNull(): null
    {
        $this->expect('N;');

        return null;
    }

    private function readScalar(string $type): string
    {
        $this->expect($type.':');
        $end = strpos($this->input, ';', $this->position);

        if ($end === false) {
            $this->fail();
        }

        $value = substr($this->input, $this->position, $end - $this->position);
        $this->position = $end + 1;

        if ($type !== 'd' && preg_match('/^-?\d+$/', $value) !== 1) {
            $this->fail();
        }

        return $value;
    }

    private function readLength(): int
    {
        if (preg_match('/\G(\d+):/', $this->input, $matches, 0, $this->position) !== 1) {
            $this->fail();
        }

        $this->position += strlen($matches[0]);

        return (int) $matches[1];
    }

    private function readQuoted(int $length): string
    {
        $this->expect('"');

        if ($this->position + $length > strlen($this->input)) {
            $this->fail();
        }

        $value = substr($this->input, $this->position, $length);
        $this->position += $length;
        $this->expect('"');

        return $value;
    }

    private function readString(): string
    {
        $this->expect('s:');
        $value = $this->readQuoted($this->readLength());
        $this->expect(';');

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readArray(int $depth): array
    {
        $this->expect('a:');

        return $this->readMembers($this->readLength(), $depth, false);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readObject(int $depth): array
    {
        $this->expect('O:');
        $class = $this->readQuoted($this->readLength());
        $this->expect(':');

        return [self::CLASS_KEY => $class, ...$this->readMembers($this->readLength(), $depth, true)];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readMembers(int $count, int $depth, bool $isObject): array
    {
        $this->expect('{');
        $members = [];

        for ($i = 0; $i < $count; $i++) {
            $key = $this->readValue($depth + 1);

            if (! is_int($key) && ! is_string($key)) {
                $this->fail();
            }

            // Protected (`\0*\0name`) and private (`\0Class\0name`) properties.
            if ($isObject && is_string($key) && str_starts_with($key, "\0")) {
                $key = substr($key, (int) strrpos($key, "\0") + 1);
            }

            $members[$key] = $this->readValue($depth + 1);
        }

        $this->expect('}');

        return $members;
    }

    /**
     * @return array<string, string>
     */
    private function readCustomObject(): array
    {
        $this->expect('C:');
        $class = $this->readQuoted($this->readLength());
        $this->expect(':');
        $length = $this->readLength();
        $this->expect('{');
        $data = substr($this->input, $this->position, $length);
        $this->position += $length;
        $this->expect('}');

        return [self::CLASS_KEY => $class, 'data' => $data];
    }

    private function readEnum(): string
    {
        $this->expect('E:');
        $value = $this->readQuoted($this->readLength());
        $this->expect(';');

        return str_replace(':', '::', $value);
    }

    private function readReference(): string
    {
        $this->position++;
        $this->expect(':');
        $this->readScalarDigits();

        return '(reference)';
    }

    private function readScalarDigits(): void
    {
        if (preg_match('/\G\d+;/', $this->input, $matches, 0, $this->position) !== 1) {
            $this->fail();
        }

        $this->position += strlen($matches[0]);
    }

    private function expect(string $token): void
    {
        if (substr($this->input, $this->position, strlen($token)) !== $token) {
            $this->fail();
        }

        $this->position += strlen($token);
    }

    private function fail(): never
    {
        throw new UnexpectedValueException('Invalid serialized value at offset '.$this->position.'.');
    }
}
