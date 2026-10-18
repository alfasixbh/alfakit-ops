<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Support;

use Illuminate\Pagination\Paginator;
use SplQueue;

/**
 * Reads Monolog entries (`[2026-10-14 09:30:12] production.ERROR: Message {"context"} []`)
 * newest first, from the END of the file, in fixed-size chunks: memory stays bounded by the
 * chunk size and the entry size cap however large the file is, and the first pages of a
 * huge file are as fast as those of a small one. Lines without a header (stack traces)
 * belong to the entry above them.
 *
 * Entries are keyed by their byte offset in the file, so one entry can be read again
 * on its own (`entryAt()`).
 *
 * @phpstan-type LogEntry array{id: string, offset: int, date: ?string, channel: ?string, level: ?string, message: string, context: ?string, truncated: bool}
 */
final class LogReader
{
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /** Bytes read per step. */
    public const CHUNK_SIZE = 65536;

    /** Bytes kept per entry (a longer stack trace is cut). */
    public const MAX_ENTRY_SIZE = 262144;

    /** Characters of the message shown in the table. */
    public const MESSAGE_LENGTH = 500;

    private const HEADER = '/^\[(?<date>\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)\]\s(?<channel>\S+?)\.(?<level>DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY):\s?(?<message>.*)$/';

    /**
     * One page of entries (newest first) matching the levels and the search.
     *
     * @param  array<string>  $levels
     * @return Paginator<string, LogEntry>
     */
    public static function paginate(string $path, int $page, int $perPage, array $levels = [], ?string $search = null): Paginator
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $skip = ($page - 1) * $perPage;
        $levels = array_values(array_intersect(array_map(strtolower(...), $levels), self::LEVELS));
        $search = ($search = trim((string) $search)) === '' ? null : mb_strtolower($search);
        $entries = [];

        self::scan($path, static function (array $entry) use (&$skip, &$entries, $perPage, $levels, $search): bool {
            if ($levels !== [] && ! in_array($entry['level'], $levels, true)) {
                return true;
            }

            if ($search !== null && ! str_contains(mb_strtolower($entry['message']."\n".($entry['context'] ?? '')."\n".($entry['channel'] ?? '')), $search)) {
                return true;
            }

            if ($skip > 0) {
                $skip--;

                return true;
            }

            $entries[$entry['id']] = $entry;

            // One more than a page tells the paginator there is a next page.
            return count($entries) <= $perPage;
        });

        return new Paginator($entries, $perPage, $page);
    }

    /**
     * Every entry, newest first, passed to `$callback` until it returns false.
     *
     * @param  callable(LogEntry): bool  $callback
     */
    public static function scan(string $path, callable $callback): void
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return;
        }

        try {
            $position = (int) filesize($path);
            // The start of the line that continues into the chunk read before (later in the file).
            $carry = '';
            $carryLength = 0;
            /** @var SplQueue<string> $pending */
            $pending = new SplQueue;
            $pendingBytes = 0;
            $truncated = false;
            $isFirstLine = true;

            while ($position > 0) {
                $read = min(self::CHUNK_SIZE, $position);
                $position -= $read;
                fseek($handle, $position);
                $chunk = (string) fread($handle, $read);

                $lines = explode("\n", $chunk.$carry);
                $lengths = array_map(strlen(...), $lines);
                // The real length of the last line (its end may have been cut from `$carry`).
                $lengths[count($lines) - 1] += $carryLength - strlen($carry);

                if ($position > 0) {
                    // The first line may start in the previous chunk.
                    $carry = (string) array_shift($lines);
                    $carryLength = (int) array_shift($lengths);
                    $offset = $position + $carryLength + 1;

                    // A single gigantic line: keep its beginning (where the header is).
                    if (strlen($carry) > self::MAX_ENTRY_SIZE) {
                        $carry = substr($carry, 0, self::MAX_ENTRY_SIZE);
                    }
                } else {
                    $carry = '';
                    $carryLength = 0;
                    $offset = 0;
                }

                $offsets = [];

                foreach ($lengths as $index => $length) {
                    $offsets[$index] = $offset;
                    $offset += $length + 1;
                }

                for ($index = count($lines) - 1; $index >= 0; $index--) {
                    $line = rtrim($lines[$index], "\r");

                    // The file's trailing newline.
                    if ($isFirstLine) {
                        $isFirstLine = false;

                        if ($line === '') {
                            continue;
                        }
                    }

                    if (preg_match(self::HEADER, $line, $matches) === 1) {
                        $entry = self::makeEntry($offsets[$index], $matches, self::drain($pending), $truncated);
                        $pendingBytes = 0;
                        $truncated = false;

                        if (! $callback($entry)) {
                            return;
                        }

                        continue;
                    }

                    // Collected newest line first; past the cap the entry's last lines go.
                    $pending->enqueue($line);
                    $pendingBytes += strlen($line) + 1;

                    while ($pendingBytes > self::MAX_ENTRY_SIZE && ! $pending->isEmpty()) {
                        $pendingBytes -= strlen($pending->dequeue()) + 1;
                        $truncated = true;
                    }
                }
            }

            // Lines before the first header (a file that doesn't start with one).
            $orphan = self::drain($pending);

            if (trim(implode('', $orphan)) !== '') {
                $callback([
                    'id' => '0',
                    'offset' => 0,
                    'date' => null,
                    'channel' => null,
                    'level' => null,
                    'message' => self::limit((string) array_shift($orphan)),
                    'context' => $orphan === [] ? null : implode("\n", $orphan),
                    'truncated' => $truncated,
                ]);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * The entry that starts at `$offset` (an id from `paginate()`), read forwards.
     *
     * @return LogEntry|null
     */
    public static function entryAt(string $path, int $offset): ?array
    {
        $size = (int) @filesize($path);

        if ($offset < 0 || $offset >= $size) {
            return null;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            fseek($handle, $offset);
            $first = fgets($handle);

            if ($first === false || preg_match(self::HEADER, rtrim($first, "\r\n"), $matches) !== 1) {
                return null;
            }

            $lines = [];
            $bytes = 0;
            $truncated = false;

            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");

                if (preg_match(self::HEADER, $line) === 1) {
                    break;
                }

                if ($bytes + strlen($line) > self::MAX_ENTRY_SIZE) {
                    $truncated = true;

                    break;
                }

                $lines[] = $line;
                $bytes += strlen($line) + 1;
            }

            return self::makeEntry($offset, $matches, $lines, $truncated);
        } finally {
            fclose($handle);
        }
    }

    public static function levelColor(?string $level): string
    {
        return match ($level) {
            'debug' => 'gray',
            'info', 'notice' => 'info',
            'warning' => 'warning',
            'error', 'critical', 'alert', 'emergency' => 'danger',
            default => 'gray',
        };
    }

    public static function levelIcon(?string $level): string
    {
        return match ($level) {
            'debug' => 'bug',
            'info', 'notice' => 'info',
            'warning' => 'triangle-alert',
            'error' => 'circle-x',
            'critical', 'alert', 'emergency' => 'octagon-alert',
            default => 'circle-help',
        };
    }

    /**
     * @param  SplQueue<string>  $pending
     * @return list<string> The lines in file order.
     */
    private static function drain(SplQueue $pending): array
    {
        $lines = [];

        while (! $pending->isEmpty()) {
            $lines[] = $pending->dequeue();
        }

        return array_reverse($lines);
    }

    /**
     * @param  array<array-key, string>  $header
     * @param  list<string>  $lines  The lines after the header line.
     * @return LogEntry
     */
    private static function makeEntry(int $offset, array $header, array $lines, bool $truncated): array
    {
        [$message, $context] = self::splitContext($header['message'], $lines);

        return [
            'id' => (string) $offset,
            'offset' => $offset,
            'date' => $header['date'],
            'channel' => $header['channel'],
            'level' => strtolower($header['level']),
            'message' => self::limit($message),
            'context' => $context,
            'truncated' => $truncated,
        ];
    }

    /**
     * Split `Message {"user":1} []` into the message and its context (pretty JSON when the
     * context is JSON, the raw text otherwise, e.g. an exception with its stack trace).
     *
     * @param  list<string>  $lines
     * @return array{string, ?string}
     */
    private static function splitContext(string $first, array $lines): array
    {
        $rest = implode("\n", $lines);
        $attempts = 0;
        $position = -1;

        while ($attempts < 5 && ($position = strpos($first, ' {', $position + 1)) !== false) {
            $attempts++;
            $candidate = substr($first, $position + 1).($rest !== '' ? "\n".$rest : '');
            $candidate = (string) preg_replace('/\s(?:\[\]|\{\})\s*$/', '', rtrim($candidate));
            $decoded = self::decodeContext($candidate);

            if ($decoded !== null) {
                return [rtrim(substr($first, 0, $position)), $decoded];
            }

            // Laravel's exception context spans lines, so it isn't valid JSON: keep it raw.
            if (str_starts_with(substr($first, $position + 1), '{"exception":"[object] ')) {
                return [rtrim(substr($first, 0, $position)), $candidate];
            }
        }

        $first = (string) preg_replace('/\s\[\]\s\[\]$|\s\[\]$/', '', $first);

        return [$first, $rest !== '' ? $rest : null];
    }

    /**
     * `{"user":1}` or `{"user":1} {"memory":"2 MB"}` (context and extra) as pretty JSON.
     */
    private static function decodeContext(string $candidate): ?string
    {
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $decoded = json_decode($candidate, true);

        if (is_array($decoded)) {
            return (string) json_encode($decoded, $flags);
        }

        // Context followed by the record's `extra`.
        $split = max((int) strrpos($candidate, '} {'), (int) strrpos($candidate, '} ['));

        if ($split > 0) {
            $context = json_decode(substr($candidate, 0, $split + 1), true);
            $extra = json_decode(substr($candidate, $split + 2), true);

            if (is_array($context) && is_array($extra)) {
                return (string) json_encode($context, $flags)."\n".(string) json_encode($extra, $flags);
            }
        }

        return null;
    }

    private static function limit(string $message): string
    {
        return mb_strlen($message) > self::MESSAGE_LENGTH ? mb_substr($message, 0, self::MESSAGE_LENGTH).'…' : $message;
    }
}
