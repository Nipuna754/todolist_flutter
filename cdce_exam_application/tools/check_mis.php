<?php

/**
 * Pre-flight check for config/config.php. Run it on the web server before
 * opening the tool to students:
 *
 *   php tools/check_mis.php                 connection, table and columns
 *   php tools/check_mis.php 931234567V      plus a real end-to-end lookup
 *
 * Nothing here writes to the MIS.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Cdce\ExamApplication\ApplicationPdf;
use Cdce\ExamApplication\Config;
use Cdce\ExamApplication\MisRepository;
use Cdce\ExamApplication\Nic;

$failures = 0;

function report(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    $failures += $ok ? 0 : 1;
    printf("[%s] %s%s%s", $ok ? ' OK ' : 'FAIL', $label, $detail === '' ? '' : ' - ' . $detail, PHP_EOL);
}

try {
    $config = Config::load();
    report('config/config.php loads', true);
} catch (Throwable $e) {
    report('config/config.php loads', false, $e->getMessage());
    exit(1);
}

$template = $config->string('template', dirname(__DIR__) . '/templates/ba_100_level_2026.pdf');
report('application template is readable', is_readable($template), $template);

try {
    $repository = MisRepository::connect($config);
    report('MIS connection', true, (string) $config->get('mis.dsn'));
} catch (Throwable $e) {
    report('MIS connection', false, $e->getMessage());
    exit(1);
}

// A structurally valid NIC that no living student can hold: year 1900, day 1,
// serial 0000. It exercises the table, the four columns and the eligibility
// clause without reading anybody's record. It must parse, or the probe never
// reaches the query.
try {
    $repository->findEligibleStudent(Nic::parse('000010000V'));
    report('table, columns and eligibility clause resolve', true);
} catch (Throwable $e) {
    report('table, columns and eligibility clause resolve', false, $e->getMessage());
}

$nicArgument = $argv[1] ?? null;
if ($nicArgument !== null) {
    try {
        $student = $repository->findEligibleStudent(Nic::parse($nicArgument));
        if ($student === null) {
            report('lookup for supplied NIC', false, 'no eligible candidate found');
        } else {
            report('lookup for supplied NIC', true, $student->registrationNo);
            $missing = $student->missingFields();
            report('all four particulars present', $missing === [], implode(', ', $missing));

            $pdf = new ApplicationPdf($template);
            $pdf->render($student);
            $out = dirname(__DIR__) . '/var/check-' . $student->slug() . '.pdf';
            @mkdir(dirname($out), 0775, true);
            file_put_contents($out, $pdf->toBinaryString());
            report('application generated', true, $out);
        }
    } catch (Throwable $e) {
        report('lookup for supplied NIC', false, $e->getMessage());
    }
}

echo PHP_EOL, $failures === 0 ? "All checks passed.\n" : "{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
