<?php
declare(strict_types=1);

function loadMessageFile(string $path): array
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException("Cannot read message file: {$path}");
    }

    $messages = [];
    foreach ($lines as $index => $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $parts = preg_split('/\s+/', $line, 2);
        if ($parts === false || count($parts) < 2 || trim($parts[1]) === '') {
            fwrite(STDERR, 'warning: message file line ' . ($index + 1) . " skipped: expected '<nick> <text>'\n");
            continue;
        }

        $messages[] = [
            'line' => $index + 1,
            'nick' => $parts[0],
            'text' => trim($parts[1]),
        ];
    }

    if ($messages === []) {
        throw new RuntimeException("No messages found in {$path}");
    }

    return $messages;
}

function loadProgress(string $path): int
{
    if (!is_file($path)) {
        return 1;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Cannot read progress file: {$path}");
    }

    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException("Invalid progress file {$path}; use --reset-progress", 0, $e);
    }

    if (!is_array($data) || !isset($data['next_line']) || !is_int($data['next_line']) || $data['next_line'] < 1) {
        throw new RuntimeException("Invalid progress file {$path}; use --reset-progress");
    }

    return $data['next_line'];
}

function saveProgress(string $path, int $nextLine): void
{
    $nextLine = max(1, $nextLine);
    $tmp = $path . '.tmp';
    $json = json_encode(['next_line' => $nextLine], JSON_THROW_ON_ERROR);

    if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException("Cannot write progress file: {$path}");
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("Cannot replace progress file: {$path}");
    }
}

function resetProgress(string $path): void
{
    if (is_file($path) && !@unlink($path)) {
        throw new RuntimeException("Cannot remove progress file: {$path}");
    }
}

function nextMessage(array $messages, int $nextLine, bool $loop): ?array
{
    foreach ($messages as $message) {
        if ($message['line'] >= $nextLine) {
            return $message;
        }
    }

    if ($loop) {
        return $messages[0] ?? null;
    }

    return null;
}
