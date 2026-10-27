<?php
require_once __DIR__ . '/../lib/bootstrap.php';
$currentUser = go_require_login($conn);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    show_message('POST required', 'Use the application forms to save or delete records.', '../index.php', 405);
}
$csrf = $_POST['csrf'] ?? null;
if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
    show_message('Form expired', 'Refresh the application and submit the form again. Nothing was saved.', '../index.php', 403);
}
$pages = ['overview', 'equipment', 'simulator', 'actions', 'reports', 'settings'];
$page = is_string($_POST['return_page'] ?? null) && in_array($_POST['return_page'], $pages, true) ? $_POST['return_page'] : 'overview';

try {
    $action = go_input('action');
    if (!go_is_admin($currentUser) && !in_array($action, ['save_action', 'delete_action'], true)) {
        show_message('Admin access required', 'Your account can view office data and manage your own saving actions.', '../index.php', 403);
    }
    switch ($action) {
        case 'save_reading':
            $id = go_id('reading_id', true);
            $reading = [
                'reading_date' => go_form_date('reading_date'),
                'meter_reading' => go_number('meter_reading', 0, 999999999),
                'meter_reset' => go_input('meter_reset', '0'),
            ];
            if (!in_array($reading['meter_reset'], ['0', '1'], true)) {
                throw new InvalidArgumentException('Meter reset must be 0 or 1.');
            }
            $before = go_lock_readings($conn);
            $after = $before;
            if ($id !== null) {
                $found = false;
                foreach ($after as &$row) {
                    if ((int) $row['reading_id'] === $id) {
                        $row = $reading + ['reading_id' => $id];
                        $found = true;
                    }
                }
                unset($row);
                if (!$found) {
                    throw new InvalidArgumentException('Reading no longer exists.');
                }
            } else {
                $after[] = $reading;
            }
            go_accept_readings($before, $after, $id !== null);
            if ($id === null) {
                $stmt = $conn->prepare('INSERT INTO tbl_reading (reading_date, meter_reading, meter_reset) VALUES (?, ?, ?)');
                $stmt->execute(array_values($reading));
            } else {
                $stmt = $conn->prepare('UPDATE tbl_reading SET reading_date=?, meter_reading=?, meter_reset=? WHERE reading_id=?');
                $stmt->execute([...array_values($reading), $id]);
            }
            $conn->commit();
            $message = $id === null ? 'Meter reading added.' : 'Meter reading updated.';
            break;

        case 'delete_reading':
            $id = go_id();
            $before = go_lock_readings($conn);
            foreach ($before as $index => $row) {
                if ((int) $row['reading_id'] === $id && !empty($row['meter_reset'])
                    && isset($before[$index + 1]) && empty($before[$index + 1]['meter_reset'])) {
                    throw new InvalidArgumentException('Mark the next reading as a meter reset before deleting this reset reading, so the unknown consumption gap is preserved.');
                }
            }
            $after = array_values(array_filter($before, fn($row) => (int) $row['reading_id'] !== $id));
            if (count($after) === count($before)) {
                throw new InvalidArgumentException('Reading no longer exists.');
            }
            go_accept_readings($before, $after, true);
            $conn->prepare('DELETE FROM tbl_reading WHERE reading_id=?')->execute([$id]);
            $conn->commit();
            $message = 'Meter reading deleted. Adjacent intervals recalculated.';
            break;

        case 'import_readings':
            $file = $_FILES['readings_file'] ?? null;
            if (!$file || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
                throw new InvalidArgumentException('Choose a CSV file to import. Upload must complete successfully.');
            }
            if (filesize($file['tmp_name']) > 1024 * 1024) {
                throw new InvalidArgumentException('CSV must be no larger than 1 MB.');
            }
            $handle = fopen($file['tmp_name'], 'rb');
            if ($handle === false) {
                throw new InvalidArgumentException('Could not read the CSV upload.');
            }
            try {
                $header = fgetcsv($handle, 0, ',', '"', '');
                if (!$header) {
                    throw new InvalidArgumentException('CSV is empty.');
                }
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
                $header = array_map('trim', $header);
                if (!in_array($header, [['reading_date', 'meter_reading'], ['reading_date', 'meter_reading', 'meter_reset']], true)) {
                    throw new InvalidArgumentException('CSV header must be reading_date,meter_reading,meter_reset (meter_reset column optional).');
                }
                $import = [];
                $line = 1;
                while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                    $line++;
                    if ($values === [null]) {
                        continue;
                    }
                    if (count($values) !== count($header)) {
                        throw new InvalidArgumentException('CSV row ' . $line . ' has the wrong number of columns.');
                    }
                    $row = array_combine($header, array_map('trim', $values));
                    $row['meter_reset'] ??= '0';
                    if (!preg_match('/^\d+(?:\.\d{1,3})?$/D', $row['meter_reading']) || $row['reading_date'] < '1000-01-01') {
                        throw new InvalidArgumentException('CSV row ' . $line . ' must contain a valid date and nonnegative reading with at most 3 decimals.');
                    }
                    $problem = go_reading_problem($row);
                    if ($problem !== null) {
                        throw new InvalidArgumentException('CSV row ' . $line . ': ' . $problem);
                    }
                    $import[] = $row;
                    if (count($import) > 2000) {
                        throw new InvalidArgumentException('Import at most 2,000 readings at once.');
                    }
                }
            } finally {
                fclose($handle);
            }
            if (!$import) {
                throw new InvalidArgumentException('CSV contains no readings.');
            }
            $before = go_lock_readings($conn);
            go_accept_readings($before, [...$before, ...$import]);
            $stmt = $conn->prepare('INSERT INTO tbl_reading (reading_date, meter_reading, meter_reset) VALUES (?, ?, ?)');
            foreach ($import as $row) {
                $stmt->execute([$row['reading_date'], $row['meter_reading'], $row['meter_reset']]);
            }
            $conn->commit();
            $message = count($import) . ' readings imported. All rows validated before saving.';
            break;

        case 'save_settings':
            $settings = [
                'office_name' => go_text('office_name', 100),
                'tariff' => go_number('tariff', 0, 10000, 3),
                'monthly_target' => go_number('monthly_target', 1, 999999999, 3),
                'monthly_budget' => go_number('monthly_budget', 1, 999999999, 2),
                'emission_factor' => go_number('emission_factor', 0, 10, 5),
                'emission_source' => go_text('emission_source', 500),
                'floor_area' => go_number('floor_area', 0.01, 10000000, 2),
                'working_days' => go_number('working_days', 1, 31, 0),
            ];
            $conn->beginTransaction();
            $stmt = $conn->prepare('INSERT INTO office_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=?');
            foreach ($settings as $key => $value) {
                $stmt->execute([$key, $value, $value]);
            }
            $conn->commit();
            $message = 'Office settings saved. Cost and emissions estimates recalculated.';
            break;

        case 'add_room':
            $name = go_text('name', 100);
            $area = go_number('area', 0, 10000000, 2);
            $conn->prepare('INSERT INTO rooms (name, area) VALUES (?, ?)')->execute([$name, $area]);
            $message = 'Room added.';
            break;

        case 'delete_room':
            $id = go_id();
            go_record($conn, 'rooms', 'room_id', $id);
            $stmt = $conn->prepare('SELECT COUNT(*) FROM equipment WHERE room_id=?');
            $stmt->execute([$id]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new InvalidArgumentException('Move or delete equipment in this room before deleting the room.');
            }
            $conn->prepare('DELETE FROM rooms WHERE room_id=?')->execute([$id]);
            $message = 'Room deleted.';
            break;

        case 'save_equipment':
            $id = go_id('equipment_id', true);
            $roomId = go_id('room_id');
            $values = [
                $roomId,
                go_text('name', 100),
                go_number('watts', 0.001, 1000000, 3),
                go_number('quantity', 1, 100000, 0),
                go_number('hours_per_day', 0, 24, 2),
                go_number('days_per_month', 0, 31, 0),
            ];
            go_record($conn, 'rooms', 'room_id', $roomId);
            if ($id === null) {
                $stmt = $conn->prepare('INSERT INTO equipment (room_id, name, watts, quantity, hours_per_day, days_per_month) VALUES (?, ?, ?, ?, ?, ?)');
            } else {
                go_record($conn, 'equipment', 'equipment_id', $id);
                $stmt = $conn->prepare('UPDATE equipment SET room_id=?, name=?, watts=?, quantity=?, hours_per_day=?, days_per_month=? WHERE equipment_id=?');
                $values[] = $id;
            }
            $stmt->execute($values);
            $message = $id === null ? 'Equipment added. Consumption is an estimate from your inputs.' : 'Equipment updated.';
            break;

        case 'delete_equipment':
            $id = go_id();
            go_record($conn, 'equipment', 'equipment_id', $id);
            $conn->prepare('DELETE FROM equipment WHERE equipment_id=?')->execute([$id]);
            $message = 'Equipment deleted.';
            break;

        case 'save_action':
            $id = go_id('action_id', true);
            if ($id !== null && !go_can_manage_action($currentUser, go_record($conn, 'actions', 'action_id', $id))) {
                show_message('Action access denied', 'You can only change actions created by your account.', '../index.php?page=actions', 403);
            }
            $status = go_input('status');
            if (!in_array($status, ['open', 'completed'], true)) {
                throw new InvalidArgumentException('Action status must be open or completed.');
            }
            $completed = $status === 'completed' ? go_form_date('completed_date') : null;
            if ($completed !== null && $completed > date('Y-m-d')) {
                throw new InvalidArgumentException('Completion date cannot be in the future.');
            }
            $values = [
                go_text('title', 160),
                go_is_admin($currentUser) ? go_text('owner', 100, false) : $currentUser['name'],
                go_form_date('due_date', true),
                $completed,
                $status,
                go_input('estimated_kwh') === '' ? null : go_number('estimated_kwh', -999999999, 999999999),
                go_text('notes', 5000, false),
            ];
            if ($id === null) {
                $stmt = $conn->prepare('INSERT INTO actions (title, owner, due_date, completed_date, status, estimated_kwh, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $values[] = $currentUser['user_id'];
            } else {
                $stmt = $conn->prepare('UPDATE actions SET title=?, owner=?, due_date=?, completed_date=?, status=?, estimated_kwh=?, notes=? WHERE action_id=?');
                $values[] = $id;
            }
            $stmt->execute($values);
            $message = $id === null ? 'Energy-saving action added.' : 'Energy-saving action updated.';
            break;

        case 'delete_action':
            $id = go_id();
            if (!go_can_manage_action($currentUser, go_record($conn, 'actions', 'action_id', $id))) {
                show_message('Action access denied', 'You can only delete actions created by your account.', '../index.php?page=actions', 403);
            }
            $conn->prepare('DELETE FROM actions WHERE action_id=?')->execute([$id]);
            $message = 'Energy-saving action deleted.';
            break;

        default:
            throw new InvalidArgumentException('Unknown form action. Refresh the application and try again.');
    }
    go_clear_old();
    go_flash('success', $message);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $_SESSION['old'] = [];
    foreach (['action', 'return_page', 'id', 'reading_id', 'reading_date', 'meter_reading', 'meter_reset',
        'office_name', 'tariff', 'monthly_target', 'monthly_budget', 'emission_factor', 'emission_source', 'floor_area', 'working_days',
        'name', 'area', 'equipment_id', 'room_id', 'watts', 'quantity', 'hours_per_day', 'days_per_month',
        'action_id', 'title', 'owner', 'due_date', 'completed_date', 'status', 'estimated_kwh', 'notes'] as $key) {
        if (is_string($_POST[$key] ?? null)) {
            $_SESSION['old'][$key] = substr($_POST[$key], 0, 5000);
        }
    }
    if ($e instanceof InvalidArgumentException) {
        $message = $e->getMessage();
    } elseif ($e instanceof PDOException && $e->getCode() === '23000') {
        $message = 'Nothing saved. A record already exists or is still used by another record.';
    } else {
        error_log('Green Office save failed: ' . $e->getMessage());
        $message = 'Could not save this change. Check database setup and try again. Nothing was partially saved.';
    }
    go_flash('error', $message);
}
header('Location: ../index.php?page=' . $page, true, 303);
exit;
