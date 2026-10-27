<?php

function go_app_path(string $path): string
{
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $segments = array_values(array_filter(explode('/', trim($requestPath, '/')), 'strlen'));
    array_pop($segments);
    if (end($segments) === 'endpoint') {
        array_pop($segments);
    }
    $base = '/' . implode('/', $segments);
    return ($base === '/' ? '' : $base) . '/' . ltrim($path, '/');
}

function go_reset_session(?array $user = null): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    if ($user !== null) {
        $_SESSION['auth_user_id'] = (int) $user['user_id'];
        $_SESSION['auth_version'] = (int) $user['auth_version'];
    }
}

function go_current_user(PDO $conn): ?array
{
    if (!isset($_SESSION['auth_user_id'], $_SESSION['auth_version'])) {
        return null;
    }
    $stmt = $conn->prepare('SELECT user_id, name, email, role, active, auth_version FROM users WHERE user_id=?');
    $stmt->execute([$_SESSION['auth_user_id']]);
    $user = $stmt->fetch();
    if (!$user || !(int) $user['active'] || !in_array($user['role'], ['admin', 'user'], true)
        || (int) $user['auth_version'] !== (int) $_SESSION['auth_version']) {
        go_reset_session();
        return null;
    }
    $user['user_id'] = (int) $user['user_id'];
    $user['active'] = (int) $user['active'];
    return $user;
}

function go_require_login(PDO $conn): array
{
    $user = go_current_user($conn);
    if ($user === null) {
        header('Location: ' . go_app_path('login.php'), true, 303);
        exit;
    }
    return $user;
}

function go_is_admin(array $user): bool
{
    return ($user['role'] ?? '') === 'admin';
}

function go_require_admin(PDO $conn): array
{
    $user = go_require_login($conn);
    if (!go_is_admin($user)) {
        show_message('Administrator access required', 'Your account cannot manage office settings, readings, equipment or other accounts.', go_app_path('index.php'), 403);
    }
    return $user;
}

function go_can_manage_action(array $user, array $action): bool
{
    return go_is_admin($user) || (isset($action['created_by']) && (int) $action['created_by'] === (int) $user['user_id']);
}

function go_validate_password(string $password): void
{
    if (!mb_check_encoding($password, 'UTF-8') || mb_strlen($password, 'UTF-8') < 12
        || strlen($password) > 72 || str_contains($password, "\0")) {
        throw new InvalidArgumentException('Password must contain at least 12 characters, at most 72 bytes, and no null characters.');
    }
}

function go_email(string $email): string
{
    $email = strtolower(trim($email));
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    return $email;
}

function go_password_input(string $key): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) {
        throw new InvalidArgumentException('Invalid password field.');
    }
    return $value;
}

function go_account_lock(PDO $conn): void
{
    $conn->beginTransaction();
    if (!$conn->query("SELECT setting_value FROM office_settings WHERE setting_key='_accounts_lock' FOR UPDATE")->fetch()) {
        throw new RuntimeException('Run database setup to enable accounts.');
    }
}

function go_attempt_login(PDO $conn, string $email, string $password, string $ip): ?array
{
    $conn->beginTransaction();
    if (!$conn->query("SELECT setting_value FROM office_settings WHERE setting_key='_auth_lock' FOR UPDATE")->fetch()) {
        throw new RuntimeException('Run database setup to enable sign-in.');
    }
    $conn->exec('DELETE FROM auth_throttle WHERE window_started < NOW() - INTERVAL 15 MINUTE');
    $keys = [hash('sha256', 'ip:' . $ip), hash('sha256', 'email:' . $email)];
    $stmt = $conn->prepare('SELECT bucket_key, attempts FROM auth_throttle WHERE bucket_key IN (?, ?)');
    $stmt->execute($keys);
    $buckets = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    if ((int) $conn->query('SELECT COUNT(*) FROM auth_throttle')->fetchColumn() + 2 - count($buckets) > 10000) {
        $conn->commit();
        return null;
    }
    $insert = $conn->prepare('INSERT IGNORE INTO auth_throttle (bucket_key, attempts, window_started) VALUES (?, 0, NOW())');
    foreach ($keys as $key) {
        $insert->execute([$key]);
    }
    $ipAttempts = (int) ($buckets[$keys[0]] ?? 0);
    $emailAttempts = (int) ($buckets[$keys[1]] ?? 0);
    if ($ipAttempts >= 30 || $emailAttempts >= 5) {
        $conn->prepare('UPDATE auth_throttle SET attempts=LEAST(attempts+1,30) WHERE bucket_key=?')->execute([$keys[0]]);
        $conn->commit();
        return null;
    }
    $stmt = $conn->prepare('SELECT user_id, name, email, password_hash, role, active, auth_version FROM users WHERE email=?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    $hash = $user['password_hash'] ?? '$2y$12$4Umg0rCJwMswRw/l.SwHvuQV01coP0eWmGzd61QH2RvAOMANUBGC.';
    $valid = strlen($password) <= 72 && !str_contains($password, "\0") && password_verify($password, $hash);
    if (!$user || !$valid || !(int) $user['active'] || !in_array($user['role'], ['admin', 'user'], true)) {
        $conn->prepare('UPDATE auth_throttle SET attempts=attempts+1 WHERE bucket_key IN (?, ?)')->execute($keys);
        $conn->commit();
        return null;
    }
    $conn->prepare('DELETE FROM auth_throttle WHERE bucket_key=?')->execute([$keys[1]]);
    $conn->commit();
    unset($user['password_hash']);
    return $user;
}
