<?php
require_once __DIR__ . '/../lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    show_message('POST required', 'Use the registration form.', go_app_path('register.php'), 405);
}

$csrf = $_POST['csrf'] ?? null;
if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
    show_message('Form expired', 'Refresh the page and submit the form again.', go_app_path('register.php'), 403);
}

try {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = go_email((string) ($_POST['email'] ?? ''));
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    if ($name === '' || strlen($name) > 100 || preg_match('/[\x00-\x1F]/', $name)) {
        throw new InvalidArgumentException('Enter a valid name using 1–100 characters.');
    }
    go_validate_password($password);
    if (!hash_equals($password, $confirm)) {
        throw new InvalidArgumentException('Password and confirmation must match.');
    }

    $conn->beginTransaction();
    $stmt = $conn->prepare('SELECT user_id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        throw new InvalidArgumentException('An account with that email already exists.');
    }

    $conn->prepare('INSERT INTO users (name, email, password_hash, role, active, auth_version) VALUES (?, ?, ?, ?, 1, 1)')
        ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'user']);
    $conn->commit();

    $_SESSION['old'] = ['action' => 'register', 'name' => $name, 'email' => $email];
    go_flash('success', 'Account created. You can sign in now.');
    header('Location: ' . go_app_path('login.php'), true, 303);
    exit;
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $_SESSION['old'] = ['action' => 'register'];
    foreach (['name', 'email'] as $field) {
        if (isset($_POST[$field]) && is_string($_POST[$field])) {
            $_SESSION['old'][$field] = substr($_POST[$field], 0, 254);
        }
    }
    $message = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Could not create the account. Check database setup and try again.';
    go_flash('error', $message);
    header('Location: ' . go_app_path('register.php'), true, 303);
    exit;
}
