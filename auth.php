<?php
require_once __DIR__ . '/../lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    show_message('POST required', 'Use the sign-in or account forms.', go_app_path('login.php'), 405);
}
$csrf = $_POST['csrf'] ?? null;
if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
    show_message('Form expired', 'Refresh the page and submit the form again.', go_app_path('login.php'), 403);
}
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$destination = 'login.php';
try {
    if ($action === 'login') {
        $email = strtolower(go_input('email'));
        $password = go_password_input('password');
        if (strlen($email) > 254) {
            $email = substr($email, 0, 254);
        }
        $user = go_attempt_login($conn, $email, $password, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($user === null) {
            $_SESSION['old'] = ['action' => 'login', 'email' => $email];
            throw new InvalidArgumentException('Unable to sign in. Check your email and password, or try again in 15 minutes.');
        }
        go_reset_session($user);
        $destination = 'index.php';
    } elseif ($action === 'logout') {
        go_reset_session();
        go_flash('success', 'Signed out.');
    } elseif ($action === 'change_password') {
        $destination = 'index.php?page=profile';
        $user = go_require_login($conn);
        $password = go_password_input('new_password');
        go_validate_password($password);
        if (!hash_equals($password, go_password_input('confirm_password'))) {
            throw new InvalidArgumentException('New password and confirmation must match.');
        }
        $current = go_password_input('current_password');
        go_account_lock($conn);
        $user = go_require_login($conn);
        $stmt = $conn->prepare('SELECT password_hash FROM users WHERE user_id=?');
        $stmt->execute([$user['user_id']]);
        if (strlen($current) > 72 || str_contains($current, "\0") || !password_verify($current, $stmt->fetchColumn())) {
            throw new InvalidArgumentException('Current password is incorrect.');
        }
        $conn->prepare('UPDATE users SET password_hash=?, auth_version=auth_version+1 WHERE user_id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['user_id']]);
        $conn->commit();
        $user['auth_version'] = (int) $user['auth_version'] + 1;
        go_reset_session($user);
        go_flash('success', 'Password changed. Other sessions have been signed out.');
    } else {
        throw new InvalidArgumentException('Unknown account action.');
    }
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    if ($action === 'change_password') {
        $_SESSION['old'] = ['action' => 'change_password'];
    } elseif ($action === 'login') {
        $_SESSION['old'] = ['action' => 'login', 'email' => is_string($_POST['email'] ?? null) ? substr(trim($_POST['email']), 0, 254) : ''];
    }
    if ($e instanceof InvalidArgumentException && $action !== 'login') {
        $message = $e->getMessage();
    } elseif ($action === 'login') {
        $message = 'Unable to sign in. Check your email and password, or try again in 15 minutes.';
    } else {
        $message = 'Could not update your account. Check database setup and try again.';
    }
    if (!$e instanceof InvalidArgumentException) {
        error_log('Green Office authentication failed: ' . $e->getMessage());
    }
    go_flash('error', $message);
}
header('Location: ' . go_app_path($destination), true, 303);
exit;
