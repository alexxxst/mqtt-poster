#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/messages.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$tmp = sys_get_temp_dir() . '/mqtt-poster-messages-' . bin2hex(random_bytes(4));
mkdir($tmp);
$messageFile = $tmp . '/messages.txt';
$progressFile = $tmp . '/messages.progress';

file_put_contents($messageFile, implode("\n", [
    'Alice hello world',
    '',
    'Bob second message',
    '',
    'Eve third message',
    'OnlyNick',
]) . "\n");

$messages = loadMessageFile($messageFile);
check(count($messages) === 3, 'three valid messages parsed');
check($messages[0] === ['line' => 1, 'nick' => 'Alice', 'text' => 'hello world'], 'first message');
check($messages[1] === ['line' => 3, 'nick' => 'Bob', 'text' => 'second message'], 'empty lines are skipped');
check($messages[2] === ['line' => 5, 'nick' => 'Eve', 'text' => 'third message'], 'line numbers are preserved');
check(nextMessage($messages, 1, false)['line'] === 1, 'next message from start');
check(nextMessage($messages, 2, false)['line'] === 3, 'next message skips empty lines');
check(nextMessage($messages, 6, false) === null, 'end of file without loop');
check(nextMessage($messages, 6, true)['line'] === 1, 'end of file with loop');

check(loadProgress($progressFile) === 1, 'missing progress starts at line 1');
saveProgress($progressFile, 4);
check(loadProgress($progressFile) === 4, 'progress saved and loaded');
resetProgress($progressFile);
check(!is_file($progressFile), 'progress reset removes file');
check(loadProgress($progressFile) === 1, 'progress after reset starts at line 1');

@unlink($messageFile);
@unlink($progressFile);
@rmdir($tmp);

echo "messages-format: PASS\n";
