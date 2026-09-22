<?php

/**
 * One-time deployment check, for a server with no PHP command line.
 *
 * It does what tools/preflight.php and tools/check_mis.php do, but over the
 * web: PHP version and extensions, the bundled vendor directory, a writable
 * var/, the MIS connection, and that the table, the four columns and the
 * eligibility clause all resolve. Optionally it generates one sample
 * application for a real candidate.
 *
 *   https://cdce.pdn.ac.lk/tools/apply_examination2/setup_check.php?token=...
 *
 * DELETE THIS FILE once the tool is live. It is guarded by a long random
 * token in config.php, but the correct end state is that it is not on the
 * server at all.
 */

declare(strict_types=1);

header('X-Robots-Tag: noindex, nofollow', true);
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

require dirname(__DIR__) . '/vendor/autoload.php';

use Cdce\ExamApplication\ApplicationPdf;
use Cdce\ExamApplication\Config;
use Cdce\ExamApplication\MisRepository;
use Cdce\ExamApplication\Nic;
use Cdce\ExamApplication\StudentRecord;

/** Nothing below runs, and nothing is disclosed, without the right token. */
function refuse(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not Found\n";
    exit;
}

$root = dirname(__DIR__);

try {
    $config = Config::load();
} catch (Throwable $e) {
    // No config yet means no token to check against, so nothing may be shown.
    refuse();
    exit;
}

$expected = $config->string('setup_token');
$supplied = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';

if ($expected === '' || strlen($expected) < 16 || !hash_equals($expected, $supplied)) {
    refuse();
}

$failures = 0;
$warnings = 0;
$rows = [];

function check(string $status, string $label, string $detail = ''): void
{
    global $failures, $warnings, $rows;
    $failures += $status === 'FAIL' ? 1 : 0;
    $warnings += $status === 'WARN' ? 1 : 0;
    $rows[] = [$status, $label, $detail];
}

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------- server
check(PHP_VERSION_ID >= 70400 ? 'OK' : 'FAIL', 'PHP 7.4 or newer', PHP_VERSION);
check(PHP_VERSION_ID < 80000 ? 'INFO' : 'INFO', 'PHP major version', PHP_VERSION_ID < 80000 ? 'running 7.x as expected' : 'running 8.x');

foreach (['pdo' => 'FAIL', 'pdo_mysql' => 'FAIL', 'zlib' => 'FAIL', 'gd' => 'WARN', 'mbstring' => 'WARN', 'iconv' => 'WARN'] as $ext => $severity) {
    check(extension_loaded($ext) ? 'OK' : $severity, 'extension ' . $ext);
}

check(
    function_exists('str_starts_with') ? 'INFO' : 'OK',
    'PHP 8 string helpers',
    function_exists('str_starts_with') ? 'native' : 'polyfilled by src/compat.php'
);

// ---------------------------------------------------------------- package
check(is_readable($root . '/vendor/autoload.php') ? 'OK' : 'FAIL', 'vendor/ is present');
check(is_readable($config->string('template', $root . '/templates/ba_100_level_2026.pdf')) ? 'OK' : 'FAIL', 'application template is readable');

$var = $root . '/var';
if (!is_dir($var)) {
    @mkdir($var, 0775, true);
}
$writable = is_dir($var) && is_writable($var);
check(
    $writable ? 'OK' : 'FAIL',
    'var/ is writable by the web user',
    $writable ? 'as ' . (function_exists('posix_getpwuid') && function_exists('posix_geteuid')
        ? (string) (posix_getpwuid(posix_geteuid())['name'] ?? '?')
        : 'the Apache user')
        : 'chown www ' . $var . ' && chmod 750 ' . $var
);

// ---------------------------------------------------------------- web exposure
$base = rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/');
check(
    'INFO',
    'confirm these return 403 or 404 in a browser',
    $base . '/config/config.php  and  ' . $base . '/var/download.log'
);

// ---------------------------------------------------------------- MIS
$repository = null;
try {
    $repository = MisRepository::connect($config);
    check('OK', 'MIS connection', (string) $config->get('mis.dsn'));
} catch (Throwable $e) {
    check('FAIL', 'MIS connection', $e->getMessage());
}

if ($repository !== null) {
    try {
        // Year 1900, day 1, serial 0000: parses, but no student can hold it.
        $repository->findEligibleStudent(Nic::parse('000010000V'));
        check('OK', 'table, columns and eligibility clause resolve');
    } catch (Throwable $e) {
        check('FAIL', 'table, columns and eligibility clause resolve', $e->getMessage());
    }
}

// ---------------------------------------------------------------- sample PDF
$sampleNic = isset($_GET['nic']) && is_string($_GET['nic']) ? trim($_GET['nic']) : '';
$sampleNote = '';

if ($sampleNic !== '' && $repository !== null) {
    try {
        $nic = Nic::parse($sampleNic);
        $student = $repository->findEligibleStudent($nic);

        if ($student === null) {
            $sampleNote = 'No eligible candidate found under that number. If you expected one, the eligibility rule is wrong.';
        } else {
            $missing = $student->missingFields();
            if ($missing !== []) {
                $sampleNote = 'Found ' . $student->registrationNo . ', but the MIS is missing: ' . implode(', ', $missing);
            } else {
                $pdf = new ApplicationPdf($config->string('template', $root . '/templates/ba_100_level_2026.pdf'));
                $pdf->render($student);
                $body = $pdf->toBinaryString();

                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="SAMPLE-' . $student->slug() . '.pdf"');
                header('Content-Length: ' . strlen($body));
                echo $body;
                exit;
            }
        }
    } catch (Throwable $e) {
        $sampleNote = 'Could not generate: ' . $e->getMessage();
    }
}

// A render that needs no database, so the PDF path can be proved on its own.
try {
    $pdf = new ApplicationPdf($config->string('template', $root . '/templates/ba_100_level_2026.pdf'));
    $pdf->render(new StudentRecord('AE/BA/00/0000', '199312304567', 'A.B. SAMPLE', 'SAMPLE TEST RECORD'));
    $bytes = strlen($pdf->toBinaryString());
    check($bytes > 10000 ? 'OK' : 'FAIL', 'a sample application renders', number_format($bytes) . ' bytes');
} catch (Throwable $e) {
    check('FAIL', 'a sample application renders', $e->getMessage());
}

$token = $supplied;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Setup check</title>
<style>
 body { font: 15px/1.5 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        margin: 0; padding: 24px 16px 60px; background: #f4f5f7; color: #1a1a1a; }
 main { max-width: 860px; margin: 0 auto; background: #fff; border: 1px solid #d7dbe0;
        border-radius: 10px; padding: 26px; }
 h1 { margin: 0 0 4px; font-size: 20px; }
 .sub { margin: 0 0 22px; color: #5a5f66; font-size: 14px; }
 table { width: 100%; border-collapse: collapse; margin-bottom: 26px; }
 th, td { text-align: left; padding: 7px 8px; border-bottom: 1px solid #eceef1;
          vertical-align: top; font-size: 14px; }
 th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #5a5f66; }
 td.s { width: 62px; font-weight: 700; }
 .OK { color: #17683a; } .FAIL { color: #a3131a; } .WARN { color: #8a5a00; } .INFO { color: #445; }
 td.d { color: #5a5f66; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12.5px;
        word-break: break-all; }
 .banner { padding: 13px 15px; border-radius: 6px; margin-bottom: 22px; font-weight: 600; }
 .good { background: #e8f5ee; color: #17683a; border-left: 4px solid #17683a; }
 .bad  { background: #fdecec; color: #a3131a; border-left: 4px solid #a3131a; }
 .danger { background: #fff4e5; border-left: 4px solid #8a5a00; color: #6b4600;
           padding: 15px; border-radius: 6px; margin-top: 8px; font-size: 14px; }
 form { margin: 0 0 8px; display: flex; gap: 8px; flex-wrap: wrap; }
 input[type=text] { flex: 1 1 220px; padding: 9px 11px; border: 1px solid #d7dbe0;
                    border-radius: 6px; font-family: ui-monospace, Menlo, Consolas, monospace; }
 button { padding: 9px 16px; border: 0; border-radius: 6px; background: #7b1113; color: #fff;
          font-weight: 600; cursor: pointer; }
 h2 { font-size: 15px; margin: 26px 0 8px; }
 code { background: #f4f5f7; padding: 1px 5px; border-radius: 3px; font-size: 13px; }
 .note { background: #f4f5f7; padding: 11px 13px; border-radius: 6px; font-size: 14px; }
</style>
</head>
<body>
<main>
    <h1>Setup check</h1>
    <p class="sub">BA 100 Level examination application &middot; one-time deployment check</p>

    <?php if ($failures === 0): ?>
        <p class="banner good">All checks passed<?= $warnings > 0 ? ' (' . $warnings . ' warning' . ($warnings === 1 ? '' : 's') . ')' : '' ?>.</p>
    <?php else: ?>
        <p class="banner bad"><?= $failures ?> check<?= $failures === 1 ? '' : 's' ?> failed. The tool is not ready.</p>
    <?php endif; ?>

    <table>
        <tr><th>Status</th><th>Check</th><th>Detail</th></tr>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td class="s <?= h($row[0]) ?>"><?= h($row[0]) ?></td>
                <td><?= h($row[1]) ?></td>
                <td class="d"><?= h($row[2]) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <h2>Generate a sample application</h2>
    <p class="sub">Enter the NIC of a candidate you know is eligible. The PDF downloads &mdash; open it and check all four particulars.</p>
    <form method="get">
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <input type="text" name="nic" placeholder="931234567V" value="<?= h($sampleNic) ?>" autocomplete="off">
        <button type="submit">Generate</button>
    </form>
    <?php if ($sampleNote !== ''): ?>
        <p class="note"><?= h($sampleNote) ?></p>
    <?php endif; ?>

    <div class="danger">
        <strong>Delete this file once the tool is live.</strong><br>
        It can read student records and is protected only by the token in the URL,
        which is sitting in your browser history and possibly in the Apache access log.<br><br>
        <code>rm /usr/local/www/cdce.pdn.ac.lk/tools/apply_examination2/public/setup_check.php</code><br><br>
        Then clear <code>setup_token</code> in <code>config/config.php</code>.
    </div>
</main>
</body>
</html>
