<?php

function go_load_data(PDO $conn): array
{
    try {
        $settings = go_defaults();
        foreach ($conn->query('SELECT setting_key, setting_value FROM office_settings')->fetchAll() as $row) {
            if (array_key_exists($row['setting_key'], $settings)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
        return [
            'settings' => $settings,
            'readings' => $conn->query('SELECT * FROM tbl_reading ORDER BY reading_date, reading_id')->fetchAll(),
            'rooms' => $conn->query('SELECT * FROM rooms ORDER BY name')->fetchAll(),
            'equipment' => $conn->query('SELECT e.*, r.name AS room_name FROM equipment e JOIN rooms r ON r.room_id=e.room_id ORDER BY r.name, e.name')->fetchAll(),
            'actions' => $conn->query("SELECT * FROM actions ORDER BY status, COALESCE(due_date, '9999-12-31'), action_id DESC")->fetchAll(),
        ];
    } catch (PDOException $e) {
        error_log('Green Office data load failed: ' . $e->getMessage());
        show_message('Database setup required', 'Run php scripts/setup.php to safely create or upgrade the Green Office tables. Existing readings are preserved.', null, 503);
    }
}

function go_input(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    if (!is_string($value)) {
        throw new InvalidArgumentException('Invalid form field: ' . $key . '.');
    }
    return trim($value);
}

function go_text(string $key, int $max, bool $required = true): string
{
    $value = go_input($key);
    if (($required && $value === '') || strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
        throw new InvalidArgumentException(str_replace('_', ' ', ucfirst($key)) . ' must contain ' . ($required ? '1' : '0') . '–' . $max . ' characters.');
    }
    return $value;
}

function go_number(string $key, float $min, float $max, int $precision = 3): string
{
    $value = go_input($key);
    $sign = $min < 0 ? '-?' : '';
    $pattern = $precision === 0 ? '/^' . $sign . '\d+$/D' : '/^' . $sign . '\d+(?:\.\d{1,' . $precision . '})?$/D';
    if (!preg_match($pattern, $value) || !is_finite((float) $value) || (float) $value < $min || (float) $value > $max) {
        throw new InvalidArgumentException(str_replace('_', ' ', ucfirst($key)) . ' must be between ' . $min . ' and ' . $max . ', with at most ' . $precision . ' decimal places.');
    }
    return $value;
}

function go_id(string $key = 'id', bool $optional = false): ?int
{
    $value = go_input($key);
    if ($optional && $value === '') {
        return null;
    }
    if (!ctype_digit($value) || (float) $value < 1 || (float) $value > 2147483647) {
        throw new InvalidArgumentException('Choose a valid record.');
    }
    return (int) $value;
}

function go_form_date(string $key, bool $optional = false): ?string
{
    $value = go_input($key);
    if ($optional && $value === '') {
        return null;
    }
    if (!go_date($value) || $value < '1000-01-01') {
        throw new InvalidArgumentException(str_replace('_', ' ', ucfirst($key)) . ' must be a valid YYYY-MM-DD date.');
    }
    return $value;
}

function go_record(PDO $conn, string $table, string $column, int $id): array
{
    $stmt = $conn->prepare("SELECT * FROM {$table} WHERE {$column} = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new InvalidArgumentException('Record no longer exists. Refresh and try again.');
    }
    return $row;
}

function go_lock_readings(PDO $conn): array
{
    $conn->beginTransaction();
    if (!$conn->query("SELECT setting_value FROM office_settings WHERE setting_key = '_readings_lock' FOR UPDATE")->fetch()) {
        throw new RuntimeException('Run database setup before saving readings.');
    }
    return $conn->query('SELECT * FROM tbl_reading ORDER BY reading_date, reading_id')->fetchAll();
}

function go_accept_readings(array $before, array $after, bool $correction = false): void
{
    try {
        go_validate_readings($after);
    } catch (InvalidArgumentException $e) {
        $oldErrors = go_reading_errors($before);
        $newErrors = go_reading_errors($after);
        if (!$correction || count($newErrors) >= count($oldErrors) || array_diff_assoc($newErrors, $oldErrors)) {
            throw $e;
        }
    }
}

function go_csv_cell($value): string
{
    $value = (string) $value;
    return preg_match('/^[\s]*[=+@-]/u', $value) ? "'" . $value : $value;
}
