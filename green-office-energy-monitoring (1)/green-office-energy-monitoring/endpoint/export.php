<?php
require_once __DIR__ . '/../lib/bootstrap.php';
go_require_login($conn);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    show_message('GET required', 'Open the readings export from Reports.', '../index.php?page=reports', 405);
}
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
if (!is_string($from) || !is_string($to) || ($from !== '' && !go_date($from)) || ($to !== '' && !go_date($to)) || ($from !== '' && $to !== '' && $from > $to)) {
    show_message('Invalid date range', 'Choose valid dates, with the start on or before the end.', '../index.php?page=reports', 400);
}
try {
    $sql = 'SELECT reading_date, meter_reading, meter_reset FROM tbl_reading WHERE 1=1';
    $params = [];
    if ($from !== '') {
        $sql .= ' AND reading_date >= ?';
        $params[] = $from;
    }
    if ($to !== '') {
        $sql .= ' AND reading_date <= ?';
        $params[] = $to;
    }
    $stmt = $conn->prepare($sql . ' ORDER BY reading_date, reading_id');
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Green Office export failed: ' . $e->getMessage());
    show_message('Export unavailable', 'Check database setup and try again.', '../index.php?page=reports', 503);
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="green-office-readings.csv"');
$out = fopen('php://output', 'wb');
fputcsv($out, ['reading_date', 'meter_reading', 'meter_reset'], ',', '"', '');
foreach ($rows as $row) {
    fputcsv($out, array_map('go_csv_cell', array_values($row)), ',', '"', '');
}
fclose($out);
