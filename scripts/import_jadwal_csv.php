<?php
/**
 * Import a Jadwal CSV (exported from Excel: Period, Class, Subject, Book,
 * Mufawwaz/Department, Number, Date, Day, Email columns) into
 * timetable_entries for a given week number.
 *
 * Teacher identity is stored raw (teacher_name, teacher_phone) with
 * teacher_id left NULL — link it to a real profiles row later (once a login
 * account exists for that teacher) with an UPDATE statement.
 *
 * Usage: php scripts/import_jadwal_csv.php <path-to-csv> <week_number>
 *
 * Connection info comes from env vars (PGHOST/PGPORT/PGDATABASE/PGUSER/
 * PGPASSWORD), same as any other Postgres client — not from .env, since this
 * needs a direct Postgres connection (for transactions/upserts), not the
 * Supabase REST API.
 */

declare(strict_types=1);

$csvPath = $argv[1] ?? null;
$weekNumber = isset($argv[2]) ? (int) $argv[2] : null;

if ($csvPath === null || $weekNumber === null || !is_readable($csvPath)) {
    fwrite(STDERR, "Usage: php scripts/import_jadwal_csv.php <path-to-csv> <week_number>\n");
    exit(1);
}

$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
    getenv('PGHOST'),
    getenv('PGPORT'),
    getenv('PGDATABASE')
);

$pdo = new PDO($dsn, getenv('PGUSER'), getenv('PGPASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// Re-importing the same week should be safe to re-run.
$pdo->prepare('delete from timetable_entries where week_number = :w')
    ->execute(['w' => $weekNumber]);

$classes = [];
foreach ($pdo->query('select id, name from classes') as $row) {
    $classes[$row['name']] = (int) $row['id'];
}

$periods = [];
foreach ($pdo->query('select id, period_label from period_slots') as $row) {
    $periods[$row['period_label']] = (int) $row['id'];
}

$insertClass = $pdo->prepare('insert into classes (name) values (:name) returning id');

$insertEntry = $pdo->prepare('
    insert into timetable_entries
        (week_number, class_id, day, period_slot_id, subject, book, teacher_name, teacher_phone, teacher_id, entry_date)
    values
        (:week_number, :class_id, :day, :period_slot_id, :subject, :book, :teacher_name, :teacher_phone, null, :entry_date)
');

$dayMap = [
    'Monday' => 'mon', 'Tuesday' => 'tue', 'Wednesday' => 'wed',
    'Thursday' => 'thu', 'Friday' => 'fri', 'Saturday' => 'sat',
];

$fh = fopen($csvPath, 'r');
if ($fh === false) {
    fwrite(STDERR, "Could not open $csvPath\n");
    exit(1);
}

// Strip a UTF-8 BOM if Excel added one.
$bom = fread($fh, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($fh);
}

$header = fgetcsv($fh);
if ($header === false) {
    fwrite(STDERR, "CSV has no header row\n");
    exit(1);
}

$imported = 0;
$skipped = 0;
$unknownPeriods = [];

$pdo->beginTransaction();
try {
    while (($row = fgetcsv($fh)) !== false) {
        $data = array_combine($header, $row);
        if ($data === false) {
            $skipped++;
            continue;
        }

        $className = trim((string) ($data['Class'] ?? ''));
        $periodLabel = trim((string) ($data['Period'] ?? ''));
        $dateStr = trim((string) ($data['Date'] ?? ''));
        $subject = trim((string) ($data['Subject'] ?? ''));
        $book = trim((string) ($data['Book'] ?? ''));
        $teacherRaw = trim((string) ($data['Mufawwaz/Department'] ?? ''));
        $phone = trim((string) ($data['Number'] ?? ''));

        if ($className === '' || $periodLabel === '' || $dateStr === '') {
            $skipped++;
            continue;
        }

        $date = DateTime::createFromFormat('d-m-Y', $dateStr);
        if ($date === false) {
            $skipped++;
            continue;
        }
        $dayName = $date->format('l');
        $day = $dayMap[$dayName] ?? null;
        if ($day === null) {
            $skipped++;
            continue;
        }

        if (!isset($periods[$periodLabel])) {
            $unknownPeriods[$periodLabel] = true;
            $skipped++;
            continue;
        }

        if (!isset($classes[$className])) {
            $insertClass->execute(['name' => $className]);
            $classes[$className] = (int) $insertClass->fetchColumn();
        }

        $teacherName = preg_replace('/^\d+\s*/', '', $teacherRaw);
        $teacherName = $teacherName !== '' ? $teacherName : null;

        $insertEntry->execute([
            'week_number' => $weekNumber,
            'class_id' => $classes[$className],
            'day' => $day,
            'period_slot_id' => $periods[$periodLabel],
            'subject' => $subject !== '' ? $subject : null,
            'book' => $book !== '' ? $book : null,
            'teacher_name' => $teacherName,
            'teacher_phone' => $phone !== '' ? $phone : null,
            'entry_date' => $date->format('Y-m-d'),
        ]);
        $imported++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Import failed, rolled back: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Imported: $imported\n";
echo "Skipped: $skipped\n";
if ($unknownPeriods !== []) {
    echo "Unknown period labels (not in period_slots, add them first): " . implode(', ', array_keys($unknownPeriods)) . "\n";
}
