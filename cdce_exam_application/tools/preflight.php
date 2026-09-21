<?php

/**
 * Checks that THIS SERVER can run the tool, before any config is written.
 * Safe to run at any time; reads nothing and writes nothing but var/.
 *
 *   php tools/preflight.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;
$warnings = 0;

function line(string $status, string $label, string $detail = ''): void
{
    global $failures, $warnings;
    $failures += $status === 'FAIL' ? 1 : 0;
    $warnings += $status === 'WARN' ? 1 : 0;
    printf("[%-4s] %s%s%s", $status, $label, $detail === '' ? '' : ' - ' . $detail, PHP_EOL);
}

echo "Server\n", str_repeat('-', 60), PHP_EOL;

line(
    PHP_VERSION_ID >= 80000 ? 'OK' : 'FAIL',
    'PHP 8.0 or newer',
    PHP_VERSION
);

foreach (['pdo' => 'FAIL', 'pdo_mysql' => 'FAIL', 'mbstring' => 'WARN', 'iconv' => 'WARN', 'zlib' => 'WARN'] as $ext => $severity) {
    line(extension_loaded($ext) ? 'OK' : $severity, "extension {$ext}");
}

echo "\nPackage\n", str_repeat('-', 60), PHP_EOL;

line(is_readable($root . '/vendor/autoload.php') ? 'OK' : 'FAIL', 'vendor/ is present', 'run composer install if missing');
line(is_readable($root . '/templates/ba_100_level_2026.pdf') ? 'OK' : 'FAIL', 'application template is readable');

$configExists = is_readable($root . '/config/config.php');
line($configExists ? 'OK' : 'WARN', 'config/config.php exists', $configExists ? '' : 'copy config/config.example.php and fill it in');

$var = $root . '/var';
if (!is_dir($var)) {
    @mkdir($var, 0775, true);
}
line(is_dir($var) && is_writable($var) ? 'OK' : 'FAIL', 'var/ is writable', $var . ' (rate-limit counters and audit log)');

echo "\nPDF generation\n", str_repeat('-', 60), PHP_EOL;

if (is_readable($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
    try {
        $pdf = new Cdce\ExamApplication\ApplicationPdf($root . '/templates/ba_100_level_2026.pdf');
        $pdf->render(new Cdce\ExamApplication\StudentRecord(
            'AE/BA/00/0000',
            '199312304567',
            'A.B. PREFLIGHT',
            'PREFLIGHT TEST RECORD',
        ));
        $bytes = strlen($pdf->toBinaryString());
        line($bytes > 10000 ? 'OK' : 'FAIL', 'a sample application renders', number_format($bytes) . ' bytes');
    } catch (Throwable $e) {
        line('FAIL', 'a sample application renders', $e->getMessage());
    }
} else {
    line('FAIL', 'a sample application renders', 'vendor/ missing');
}

echo "\nMIS\n", str_repeat('-', 60), PHP_EOL;

if (!$configExists) {
    line('SKIP', 'MIS connection', 'no config/config.php yet - run tools/check_mis.php after writing it');
} else {
    try {
        $config = Cdce\ExamApplication\Config::load();
        Cdce\ExamApplication\MisRepository::connect($config);
        line('OK', 'MIS connection', (string) $config->get('mis.dsn'));
        line('OK', 'next step', 'run php tools/check_mis.php to verify the table, columns and eligibility rule');
    } catch (Throwable $e) {
        line('FAIL', 'MIS connection', $e->getMessage());
    }
}

echo PHP_EOL;
if ($failures > 0) {
    printf("%d check(s) failed%s%s", $failures, $warnings > 0 ? ", {$warnings} warning(s)" : '', PHP_EOL);
    exit(1);
}
printf("All checks passed%s.%s", $warnings > 0 ? " ({$warnings} warning(s))" : '', PHP_EOL);
