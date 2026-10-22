<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../conn/conn.php';

function admin_prompt(string $label, bool $secret = false): string
{
    $interactive = stream_isatty(STDIN);
    $terminal = null;
    if ($interactive) {
        fwrite(STDERR, $label . ': ');
        if ($secret) {
            if (PHP_OS_FAMILY === 'Windows' || !function_exists('shell_exec')) {
                throw new RuntimeException('Password hiding unavailable. Supply name, email, password and confirmation as four lines through redirected stdin.');
            }
            $terminal = trim((string) shell_exec('stty -g 2>/dev/null'));
            if ($terminal === '') {
                throw new RuntimeException('Could not hide password input. Use redirected stdin.');
            }
            shell_exec('stty -echo');
        }
    }
    try {
        $line = fgets(STDIN);
        if ($line === false) {
            throw new InvalidArgumentException('Expected name, email, password and confirmation as four input lines.');
        }
        return rtrim($line, "\r\n");
    } finally {
        if ($terminal !== null) {
            shell_exec('stty ' . escapeshellarg($terminal));
            fwrite(STDERR, "\n");
        }
    }
}

try {
    $name = trim(admin_prompt('Administrator name'));
    $email = go_email(admin_prompt('Email address'));
    $password = admin_prompt('Password (at least 12 characters, at most 72 bytes)', true);
    $confirmation = admin_prompt('Confirm password', true);
    if ($name === '' || strlen($name) > 100 || preg_match('/[\x00-\x1F]/', $name)) {
        throw new InvalidArgumentException('Name must contain 1–100 characters without control characters.');
    }
    go_validate_password($password);
    if (!hash_equals($password, $confirmation)) {
        throw new InvalidArgumentException('Passwords must match.');
    }
    go_account_lock($conn);
    if ((int) $conn->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn() > 0) {
        throw new InvalidArgumentException('An active administrator already exists. Manage accounts through the administrator panel.');
    }
    $conn->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'admin')")->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
    $conn->commit();
    echo "Administrator created. Sign in with the email and password you provided.\n";
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $message = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
        ? $e->getMessage() : 'Could not create the administrator. Check database setup and choose an unused email.';
    fwrite(STDERR, 'Administrator setup failed: ' . $message . "\n");
    exit(1);
}
