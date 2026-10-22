<?php
require_once __DIR__ . '/../lib/bootstrap.php';
$administrator = go_require_admin($conn);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    show_message('POST required', 'Use the account management form.', go_app_path('index.php?page=users'), 405);
}
$csrf = $_POST['csrf'] ?? null;
if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
    show_message('Form expired', 'Refresh account management and try again.', go_app_path('index.php?page=users'), 403);
}
try {
    if (go_input('action') !== 'save_user') {
        throw new InvalidArgumentException('Unknown account action.');
    }
    $id = go_id('user_id', true);
    $name = go_text('name', 100);
    $email = go_email(go_input('email'));
    $role = go_input('role');
    $active = go_input('active');
    $password = go_password_input('password');
    if (!in_array($role, ['admin', 'user'], true) || !in_array($active, ['0', '1'], true)) {
        throw new InvalidArgumentException('Choose a valid role and account status.');
    }
    if ($id === null || $password !== '') {
        go_validate_password($password);
    }
    go_account_lock($conn);
    $administrator = go_require_admin($conn);
    if ($id === null) {
        $conn->prepare('INSERT INTO users (name, email, password_hash, role, active) VALUES (?, ?, ?, ?, ?)')->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $active]);
        $message = 'Account created.';
    } else {
        $old = go_record($conn, 'users', 'user_id', $id);
        if ($id === $administrator['user_id'] && ($role !== 'admin' || $active !== '1')) {
            throw new InvalidArgumentException('You cannot deactivate your own account or remove your own administrator role.');
        }
        if ($old['role'] === 'admin' && (int) $old['active'] === 1 && ($role !== 'admin' || $active !== '1')
            && (int) $conn->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn() <= 1) {
            throw new InvalidArgumentException('Keep at least one active administrator.');
        }
        $hash = $password === '' ? $old['password_hash'] : password_hash($password, PASSWORD_DEFAULT);
        $conn->prepare('UPDATE users SET name=?, email=?, password_hash=?, role=?, active=?, auth_version=auth_version+1 WHERE user_id=?')->execute([$name, $email, $hash, $role, $active, $id]);
        $message = 'Account updated. Existing sessions for that account have been revoked.';
        if ($id === $administrator['user_id']) {
            $administrator = ['user_id' => $id, 'auth_version' => (int) $old['auth_version'] + 1];
        }
    }
    $conn->commit();
    if ($id === $administrator['user_id']) {
        go_reset_session($administrator);
    }
    go_clear_old();
    go_flash('success', $message);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $_SESSION['old'] = ['action' => 'save_user'];
    foreach (['user_id', 'name', 'email', 'role', 'active'] as $field) {
        if (is_string($_POST[$field] ?? null)) {
            $_SESSION['old'][$field] = substr($_POST[$field], 0, 254);
        }
    }
    if ($e instanceof InvalidArgumentException) {
        $message = $e->getMessage();
    } elseif ($e instanceof PDOException && $e->getCode() === '23000') {
        $message = 'An account with that email already exists.';
    } else {
        error_log('Green Office account update failed: ' . $e->getMessage());
        $message = 'Could not save the account. Check database setup and try again.';
    }
    go_flash('error', $message);
}
header('Location: ' . go_app_path('index.php?page=users'), true, 303);
exit;
