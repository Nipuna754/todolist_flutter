<?php

/**
 * Dependency-free test runner: `php tests/run.php`.
 *
 * The MIS tests use an in-memory SQLite database shaped like the MIS student
 * table, so the suite runs on a laptop without reaching 10.40.129.2.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Cdce\ExamApplication\AmbiguousStudentException;
use Cdce\ExamApplication\ApplicationPdf;
use Cdce\ExamApplication\Config;
use Cdce\ExamApplication\MisRepository;
use Cdce\ExamApplication\Nic;
use Cdce\ExamApplication\RateLimiter;
use Cdce\ExamApplication\StudentRecord;

$passed = 0;
$failed = 0;

function test(string $name, callable $body): void
{
    global $passed, $failed;
    try {
        $body();
        $passed++;
        echo "  ok   {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL {$name}\n       {$e->getMessage()}\n";
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%sexpected %s, got %s',
            $message === '' ? '' : $message . ': ',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertThrows(string $class, callable $body): void
{
    try {
        $body();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return;
        }
        throw new RuntimeException('expected ' . $class . ', got ' . $e::class . ': ' . $e->getMessage());
    }
    throw new RuntimeException('expected ' . $class . ', nothing thrown');
}

echo "National ID numbers\n";

test('accepts the old format and tidies it up', function (): void {
    assertSame('931234567V', Nic::parse(' 93 1234 567 v ')->value());
});

test('accepts the new format', function (): void {
    assertSame('199312304567', Nic::parse('199312304567')->value());
});

test('converts old to new', function (): void {
    assertSame('197312304567', Nic::parse('731234567V')->newFormat());
});

test('converts new to the old 9 digits', function (): void {
    assertSame('731234567', Nic::parse('197312304567')->oldFormatDigits());
});

test('old and new forms of one number share their lookup variants', function (): void {
    $old = Nic::parse('731234567V')->lookupVariants();
    $new = Nic::parse('197312304567')->lookupVariants();
    foreach (['731234567V', '197312304567'] as $expected) {
        assertTrue(in_array($expected, $old, true), "old form is missing {$expected}");
        assertTrue(in_array($expected, $new, true), "new form is missing {$expected}");
    }
});

test('does not fold a 2000s number down to a 1900 number', function (): void {
    $nic = Nic::parse('200012345678');
    assertTrue(!$nic->hasOldFormatEquivalent(), 'a 2000 birth has no old format equivalent');
    assertSame(['200012345678'], $nic->lookupVariants());
});

test('still folds a 1900s number down to the old format', function (): void {
    $nic = Nic::parse('199912304567');
    assertTrue($nic->hasOldFormatEquivalent(), 'a 1999 birth does have an old format equivalent');
    assertTrue(in_array('991234567V', $nic->lookupVariants(), true), 'the old form is missing');
});

test('keeps a female day-of-year (500 added) valid', function (): void {
    assertSame('935234567V', Nic::parse('935234567V')->value());
});

test('rejects an impossible day of the year', function (): void {
    assertThrows(InvalidArgumentException::class, static fn () => Nic::parse('939994567V'));
});

test('rejects a wrong length', function (): void {
    assertThrows(InvalidArgumentException::class, static fn () => Nic::parse('12345'));
});

test('rejects a check letter other than V or X', function (): void {
    assertThrows(InvalidArgumentException::class, static fn () => Nic::parse('931234567Z'));
});

test('never puts a full number in a log line', function (): void {
    assertTrue(!str_contains(Nic::parse('931234567V')->masked(), '1234567'), 'mask leaked the serial');
});

echo "\nStudent records\n";

test('squashes the double spaces the MIS is full of', function (): void {
    $student = StudentRecord::fromRow([
        'registration_no' => ' AE/BA/21/1234 ',
        'nic' => '199312304567',
        'name_with_initials' => "K.A.N.M.\u{00A0} PERERA",
        'name_in_full' => 'KURUKULASURIYA  ARACHCHIGE   NIMAL PERERA',
    ]);
    assertSame('AE/BA/21/1234', $student->registrationNo);
    assertSame('K.A.N.M. PERERA', $student->nameWithInitials);
    assertSame('KURUKULASURIYA ARACHCHIGE NIMAL PERERA', $student->nameInFull);
});

test('tidies a NIC that the MIS stored with spaces and dashes', function (): void {
    $student = StudentRecord::fromRow(['registration_no' => 'AE/BA/20/0009', 'nic' => '88 5234-567 x']);
    assertSame('885234567X', $student->nationalId);
});

test('keeps the format the MIS holds rather than converting it', function (): void {
    assertSame('931234567V', StudentRecord::fromRow(['nic' => '931234567V'])->nationalId);
    assertSame('200012345678', StudentRecord::fromRow(['nic' => '200012345678'])->nationalId);
});

test('prints an unrecognisable NIC as recorded instead of dropping it', function (): void {
    assertSame('PENDING', StudentRecord::fromRow(['nic' => ' PENDING '])->nationalId);
});

test('reports which particulars are missing', function (): void {
    $student = StudentRecord::fromRow(['registration_no' => 'AE/BA/21/1234', 'nic' => '199312304567']);
    assertSame(['Name with Initials', 'Name in Full'], $student->missingFields());
});

test('makes a filename-safe slug', function (): void {
    assertSame('AE-BA-21-1234', StudentRecord::fromRow(['registration_no' => 'AE/BA/21/1234'])->slug());
});

echo "\nMIS lookup\n";

function misFixture(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec(
        'CREATE TABLE student (
            reg_no TEXT, nic_no TEXT, name_with_initials TEXT, name_in_full TEXT,
            course TEXT, level INTEGER, academic_year TEXT, status TEXT
        )'
    );
    $insert = $pdo->prepare('INSERT INTO student VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $insert->execute(['AE/BA/21/1234', '931234567V', 'K.A.N.M. PERERA', 'KURUKULASURIYA ARACHCHIGE NIMAL PERERA', 'BA', 100, '2026', 'ACTIVE']);
    $insert->execute(['AE/BA/22/0001', '199512304567', 'S.D. SILVA', 'SAMARAWEERA DON SILVA', 'BA', 200, '2026', 'ACTIVE']);
    $insert->execute(['AE/BA/20/0009', '88 1234-567 X', 'T.B. BANDARA', 'THENNAKOON BANDARA', 'BA', 100, '2026', 'ACTIVE']);

    return $pdo;
}

function misConfig(): Config
{
    return Config::fromArray([
        'mis' => [
            'table' => 'student',
            'columns' => [
                'registration_no' => 'reg_no',
                'nic' => 'nic_no',
                'name_with_initials' => 'name_with_initials',
                'name_in_full' => 'name_in_full',
            ],
            'eligibility' => [
                'sql' => 'course = :course AND level = :level AND academic_year = :year AND status = :status',
                'params' => ['course' => 'BA', 'level' => 100, 'year' => '2026', 'status' => 'ACTIVE'],
            ],
        ],
    ]);
}

test('finds an eligible candidate by the NIC as stored', function (): void {
    $student = (new MisRepository(misFixture(), misConfig()))->findEligibleStudent(Nic::parse('931234567V'));
    assertTrue($student !== null, 'no student found');
    assertSame('AE/BA/21/1234', $student->registrationNo);
    assertSame('K.A.N.M. PERERA', $student->nameWithInitials);
});

test('finds the same candidate when the student types the other NIC format', function (): void {
    $student = (new MisRepository(misFixture(), misConfig()))->findEligibleStudent(Nic::parse('199312304567'));
    assertTrue($student !== null, 'the 12 digit form did not match the stored 9 digit form');
    assertSame('AE/BA/21/1234', $student->registrationNo);
});

test('matches a stored NIC that has spaces and dashes in it', function (): void {
    $student = (new MisRepository(misFixture(), misConfig()))->findEligibleStudent(Nic::parse('881234567X'));
    assertTrue($student !== null, 'stored formatting defeated the lookup');
    assertSame('AE/BA/20/0009', $student->registrationNo);
});

test('does not hand a 100 level form to a 200 level student', function (): void {
    assertSame(null, (new MisRepository(misFixture(), misConfig()))->findEligibleStudent(Nic::parse('199512304567')));
});

test('returns null for a NIC the MIS does not hold', function (): void {
    assertSame(null, (new MisRepository(misFixture(), misConfig()))->findEligibleStudent(Nic::parse('770010001V')));
});

test('refuses to guess when one NIC matches two eligible candidates', function (): void {
    $pdo = misFixture();
    $pdo->prepare('INSERT INTO student VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['AE/BA/21/9999', '931234567V', 'K.A.N. PERERA', 'KURUKULASURIYA NIMAL', 'BA', 100, '2026', 'ACTIVE']);

    assertThrows(
        AmbiguousStudentException::class,
        static fn () => (new MisRepository($pdo, misConfig()))->findEligibleStudent(Nic::parse('931234567V'))
    );
});

test('rejects a table name from config that is not a plain identifier', function (): void {
    $config = Config::fromArray([
        'mis' => [
            'table' => 'student; DROP TABLE student',
            'columns' => misConfig()->array('mis.columns'),
        ],
    ]);

    assertThrows(
        RuntimeException::class,
        static fn () => (new MisRepository(misFixture(), $config))->findEligibleStudent(Nic::parse('931234567V'))
    );
});

test('rejects an eligibility parameter that would rebind a NIC placeholder', function (): void {
    $config = Config::fromArray([
        'mis' => [
            'table' => 'student',
            'columns' => misConfig()->array('mis.columns'),
            'eligibility' => [
                'sql' => 'course = :nic_variant_0',
                'params' => ['nic_variant_0' => 'BA'],
            ],
        ],
    ]);

    assertThrows(
        RuntimeException::class,
        static fn () => (new MisRepository(misFixture(), $config))->findEligibleStudent(Nic::parse('931234567V'))
    );
});

echo "\nRate limiting\n";

test('blocks once the attempt cap is reached and reports the wait', function (): void {
    $dir = sys_get_temp_dir() . '/cdce-rate-' . bin2hex(random_bytes(4));
    $limiter = new RateLimiter($dir, 3, 900);

    for ($i = 0; $i < 3; $i++) {
        assertTrue(!$limiter->tooManyAttempts('10.0.0.1'), "blocked too early at attempt {$i}");
        $limiter->record('10.0.0.1');
    }

    assertTrue($limiter->tooManyAttempts('10.0.0.1'), 'the cap was not enforced');
    assertTrue(!$limiter->tooManyAttempts('10.0.0.2'), 'another client was caught by the cap');
    assertTrue($limiter->secondsUntilReset('10.0.0.1') > 0, 'no wait reported');

    array_map('unlink', glob($dir . '/*.txt') ?: []);
    @rmdir($dir);
});

test('forgets attempts once the window has passed', function (): void {
    $dir = sys_get_temp_dir() . '/cdce-rate-' . bin2hex(random_bytes(4));
    $limiter = new RateLimiter($dir, 2, 1);
    $limiter->record('10.0.0.3');
    $limiter->record('10.0.0.3');
    assertTrue($limiter->tooManyAttempts('10.0.0.3'), 'the cap was not enforced');

    sleep(2);
    assertTrue(!$limiter->tooManyAttempts('10.0.0.3'), 'the window did not expire');

    array_map('unlink', glob($dir . '/*.txt') ?: []);
    @rmdir($dir);
});

echo "\nGenerated application\n";

function renderUncompressed(StudentRecord $student): string
{
    $pdf = new ApplicationPdf(dirname(__DIR__) . '/templates/ba_100_level_2026.pdf');
    $pdf->SetCompression(false);
    $pdf->render($student);

    return $pdf->toBinaryString();
}

$sample = new StudentRecord(
    'AE/BA/21/1234',
    '199312304567',
    'K.A.N.M. PERERA',
    'KURUKULASURIYA ARACHCHIGE NIMAL MAHINDA PERERA',
);

test('keeps both pages of the template', function () use ($sample): void {
    $pdf = renderUncompressed($sample);
    assertTrue(str_starts_with($pdf, '%PDF-'), 'not a PDF');
    // '/Type /Pages' is the page tree node, not a page.
    $pages = substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages');
    assertSame(2, $pages, 'expected a two page application');
});

test('prints all four particulars', function () use ($sample): void {
    $pdf = renderUncompressed($sample);
    foreach ([
        'AE/BA/21/1234',
        '199312304567',
        'K.A.N.M. PERERA',
        'KURUKULASURIYA',
    ] as $needle) {
        assertTrue(str_contains($pdf, '(' . $needle), "the application does not carry {$needle}");
    }
});

test('wraps a long name in full instead of running off the page', function (): void {
    $pdf = renderUncompressed(new StudentRecord(
        'AE/BA/21/1234',
        '199312304567',
        'W.A.D.M.K.B. WICKRAMASINGHEPATHIRANNEHELAGE',
        'WELIGAMAGE ARACHCHIGE DON MAHESH KUMARA BANDARA WICKRAMASINGHEPATHIRANNEHELAGE JAYASUNDARA',
    ));
    assertTrue(str_contains($pdf, '(WELIGAMAGE'), 'the long name was not printed');
    // The tail lands mid-line once the name is wrapped, so anchor on the close.
    assertTrue(str_contains($pdf, 'JAYASUNDARA)'), 'the tail of the long name was dropped');
});

test('leaves the name areas untouched when the MIS has no name', function (): void {
    $pdf = renderUncompressed(new StudentRecord('AE/BA/21/1234', '199312304567', '', ''));
    assertTrue(str_contains($pdf, '(AE/BA/21/1234'), 'the registration number was not printed');
});

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
