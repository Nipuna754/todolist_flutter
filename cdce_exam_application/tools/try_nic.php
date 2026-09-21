<?php

/**
 * Shows how a National ID number is interpreted, and what the MIS lookup will
 * compare it against. Needs no database, so it can be run anywhere:
 *
 *   php tools/try_nic.php 931234567V 199312304567 93-1234-567-v
 *   php tools/try_nic.php --file=nics.txt
 *
 * To check a number against the live MIS instead, use tools/check_mis.php.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Cdce\ExamApplication\Nic;

$options = getopt('', ['file::']);
$inputs = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $a): bool => !str_starts_with($a, '--')
));

if (isset($options['file'])) {
    $contents = @file_get_contents((string) $options['file']);
    if ($contents === false) {
        fwrite(STDERR, 'Cannot read ' . $options['file'] . PHP_EOL);
        exit(1);
    }
    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '' && !str_starts_with($line, '#')) {
            $inputs[] = $line;
        }
    }
}

if ($inputs === []) {
    fwrite(STDERR, "Usage: php tools/try_nic.php <nic> [nic ...] | --file=list.txt\n");
    exit(1);
}

$valid = 0;
$invalid = 0;

foreach ($inputs as $input) {
    echo str_repeat('-', 68), PHP_EOL;
    printf("input            %s%s", $input, PHP_EOL);

    try {
        $nic = Nic::parse($input);
    } catch (InvalidArgumentException $e) {
        $invalid++;
        printf("result           REJECTED%s", PHP_EOL);
        printf("student sees     %s%s", $e->getMessage(), PHP_EOL);
        continue;
    }

    $valid++;
    $day = (int) substr($nic->newFormat(), 4, 3);

    printf("result           accepted%s", PHP_EOL);
    printf("normalised       %s%s", $nic->value(), PHP_EOL);
    printf("12 digit form    %s%s", $nic->newFormat(), PHP_EOL);
    printf(
        "9 digit form     %s%s",
        $nic->hasOldFormatEquivalent() ? $nic->oldFormatDigits() : 'n/a (issued only for 19xx births)',
        PHP_EOL
    );
    printf("born             %d, day %d%s%s", (int) substr($nic->newFormat(), 0, 4), $day > 500 ? $day - 500 : $day, $day > 500 ? ' (female)' : '', PHP_EOL);
    printf("MIS compared to  %s%s", implode(', ', $nic->lookupVariants()), PHP_EOL);
    printf("in the audit log %s%s", $nic->masked(), PHP_EOL);
}

echo str_repeat('-', 68), PHP_EOL;
printf("%d accepted, %d rejected%s", $valid, $invalid, PHP_EOL);
