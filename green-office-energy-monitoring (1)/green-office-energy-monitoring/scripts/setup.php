<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../lib/energy.php';
require_once __DIR__ . '/../lib/auth.php';

if (array_diff(array_slice($argv, 1), ['--demo']) !== []) {
    fwrite(STDERR, "Usage: php scripts/setup.php [--demo]\n");
    exit(1);
}
$demo = in_array('--demo', $argv, true);
$host = getenv('GREEN_DB_HOST') ?: '127.0.0.1';
$port = getenv('GREEN_DB_PORT') ?: '3306';
$name = getenv('GREEN_DB_NAME') ?: 'green_office_db';
$user = getenv('GREEN_DB_USER') ?: 'root';
$password = getenv('GREEN_DB_PASSWORD') ?: '';

try {
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name) || !ctype_digit($port)) {
        throw new RuntimeException('Invalid GREEN_DB_NAME or GREEN_DB_PORT.');
    }
    $db = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->exec("USE `{$name}`");
    $schema = preg_replace('/^\s*--.*$/m', '', file_get_contents(__DIR__ . '/../green_office_db.sql'));
    foreach (explode(';', $schema) as $statement) {
        if (trim($statement) !== '') {
            $db->exec($statement);
        }
    }

    $columns = $db->query('SHOW COLUMNS FROM tbl_reading')->fetchAll(PDO::FETCH_UNIQUE);
    if (strtolower($columns['meter_reading']['Type']) !== 'decimal(12,3)') {
        $unsafe = (int) $db->query('SELECT COUNT(*) FROM tbl_reading WHERE ABS(meter_reading)>999999999.999 OR meter_reading<>ROUND(meter_reading,3)')->fetchColumn();
        if ($unsafe > 0) {
            throw new RuntimeException('Existing meter values cannot fit DECIMAL(12,3) without changing values. Readings untouched; review those values before upgrading.');
        }
        $db->exec('ALTER TABLE tbl_reading MODIFY meter_reading DECIMAL(12,3) NOT NULL');
    }
    if (!isset($columns['meter_reset'])) {
        $db->exec('ALTER TABLE tbl_reading ADD meter_reset TINYINT NOT NULL DEFAULT 0');
    }
    $duplicateDates = (int) $db->query('SELECT COUNT(*) FROM (SELECT reading_date FROM tbl_reading GROUP BY reading_date HAVING COUNT(*) > 1) duplicates')->fetchColumn();
    $hasUniqueDate = false;
    foreach ($db->query('SHOW INDEX FROM tbl_reading')->fetchAll() as $index) {
        if ($index['Column_name'] === 'reading_date' && (int) $index['Non_unique'] === 0) {
            $hasUniqueDate = true;
        }
    }
    if (!$hasUniqueDate && $duplicateDates === 0) {
        $db->exec('ALTER TABLE tbl_reading ADD UNIQUE KEY reading_date_unique (reading_date)');
    }

    $actionColumns = $db->query('SHOW COLUMNS FROM actions')->fetchAll(PDO::FETCH_UNIQUE);
    if (!isset($actionColumns['created_by'])) {
        $db->exec('ALTER TABLE actions ADD created_by INT NULL');
    }
    $actionForeignKey = $db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='actions' AND COLUMN_NAME='created_by' AND REFERENCED_TABLE_NAME='users'")->fetchColumn();
    if (!(int) $actionForeignKey) {
        $db->exec('ALTER TABLE actions ADD CONSTRAINT actions_created_by_user FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL');
    }

    $db->beginTransaction();
    $db->query("SELECT setting_value FROM office_settings WHERE setting_key='_readings_lock' FOR UPDATE")->fetch();
    $counts = [];
    foreach (['tbl_reading', 'rooms', 'equipment', 'actions'] as $table) {
        $counts[$table] = (int) $db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
    $defaults = go_defaults();
    $defaults['data_mode'] = 'manual';
    $originalSample = [
        ['2026-04-01', '12000.000'], ['2026-05-01', '12850.000'],
        ['2026-06-01', '13790.000'], ['2026-07-01', '14760.000'],
        ['2026-08-01', '15600.000'], ['2026-09-01', '16380.000'],
    ];
    if ($counts['tbl_reading'] === 6 && array_sum($counts) === 6
        && $db->query('SELECT reading_date, meter_reading FROM tbl_reading ORDER BY reading_date')->fetchAll(PDO::FETCH_NUM) === $originalSample) {
        $defaults['data_mode'] = 'demo';
    }
    $stmt = $db->prepare('INSERT IGNORE INTO office_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ($defaults as $key => $value) {
        $stmt->execute([$key, (string) $value]);
    }
    $seeded = false;
    if ($demo && array_sum($counts) === 0) {
        $readingStmt = $db->prepare('INSERT INTO tbl_reading (reading_date, meter_reading) VALUES (?, ?)');
        foreach ([['2026-04-01',12000], ['2026-05-01',12850], ['2026-06-01',13790], ['2026-07-01',14760], ['2026-08-01',15600], ['2026-09-01',16380], ['2026-10-01',17050]] as $reading) {
            $readingStmt->execute($reading);
        }
        $roomStmt = $db->prepare('INSERT INTO rooms (name, area) VALUES (?, ?)');
        $equipmentStmt = $db->prepare('INSERT INTO equipment (room_id, name, watts, quantity, hours_per_day, days_per_month) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ([
            ['Workspace', 75, [['Desktop computers', 150, 12, 8, 22], ['Ceiling lights', 36, 10, 9, 22]]],
            ['Meeting room', 25, [['Air conditioner', 1500, 1, 5, 22], ['Display', 100, 1, 3, 22]]],
            ['Break room', 20, [['Refrigerator (average draw)', 80, 1, 24, 30], ['Kettle', 1500, 1, 0.3, 22]]],
        ] as [$room, $area, $equipment]) {
            $roomStmt->execute([$room, $area]);
            $roomId = $db->lastInsertId();
            foreach ($equipment as $item) {
                $equipmentStmt->execute([$roomId, ...$item]);
            }
        }
        $actionStmt = $db->prepare('INSERT INTO actions (title, owner, due_date, completed_date, status, estimated_kwh, notes) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $actionStmt->execute(['Enable computer sleep schedules', 'Facilities team', '2026-09-01', '2026-08-15', 'completed', 80, 'Demo action. Estimated benefit only; before/after observations do not prove causation.']);
        $actionStmt->execute(['Replace workspace lights with LEDs', 'Office manager', '2026-10-20', null, 'open', 39.6, 'Demo proposal. Confirm actual wattage, schedule and replacement cost first.']);
        $actionStmt->execute(['Review meeting room cooling schedule', 'Facilities team', '2026-10-05', null, 'open', null, 'Demo action. Check comfort requirements and occupancy before changing schedules.']);
        $db->prepare("UPDATE office_settings SET setting_value='demo' WHERE setting_key='data_mode'")->execute();
        $seeded = true;
    }
    $db->query("SELECT setting_value FROM office_settings WHERE setting_key='_accounts_lock' FOR UPDATE")->fetch();
    $demoAccounts = false;
    $mode = $db->query("SELECT setting_value FROM office_settings WHERE setting_key='data_mode'")->fetchColumn();
    if ($demo && $mode === 'demo' && (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $account = $db->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
        $account->execute(['Demo administrator', 'admin@greenoffice.test', password_hash('GreenAdmin!2026', PASSWORD_DEFAULT), 'admin']);
        $account->execute(['Demo office user', 'user@greenoffice.test', password_hash('GreenUser!2026', PASSWORD_DEFAULT), 'user']);
        $demoAccounts = true;
    }
    $db->commit();
    echo "Schema ready. Existing readings preserved.\n";
    if ($seeded) {
        echo "Explicit demo data added: 7 readings, 3 rooms, 6 equipment records, 3 actions.\n";
    } elseif ($demo) {
        echo "Demo seed skipped: existing records found. No data replaced.\n";
    }
    if ($demoAccounts) {
        echo "LOCAL DEMO ACCOUNTS ONLY: admin@greenoffice.test / GreenAdmin!2026 and user@greenoffice.test / GreenUser!2026. Replace these public demo credentials before sharing access.\n";
    } elseif ((int) $db->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn() === 0) {
        echo "No active administrator. Run php scripts/create-admin.php to create your first administrator.\n";
    }
    if ($duplicateDates > 0) {
        echo "Legacy duplicate dates retained. Correct them in the app, then rerun setup to add the unique-date index.\n";
    }
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Setup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
