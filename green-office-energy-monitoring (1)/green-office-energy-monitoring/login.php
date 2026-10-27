<?php
require_once __DIR__ . '/lib/bootstrap.php';
if (go_current_user($conn) !== null) {
    header('Location: index.php', true, 302);
    exit;
}
$loginSettings = go_defaults();
try {
    foreach ($conn->query("SELECT setting_key, setting_value FROM office_settings WHERE setting_key IN ('office_name', 'data_mode')")->fetchAll() as $setting) {
        $loginSettings[$setting['setting_key']] = $setting['setting_value'];
    }
} catch (PDOException $e) {
    error_log('Green Office login settings unavailable: ' . $e->getMessage());
    show_message('Database setup required', 'Ask the office administrator to complete the application setup.', null, 503);
}
$flash = go_get_flash();
$loginEmail = go_old_action() === 'login' ? go_old('email') : '';
go_clear_old();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173f35">
    <title>Sign in · Green Office</title>
    <link rel="icon" href="assets/leaf.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="auth-page">
<a class="skip-link" href="#login-form">Skip to sign in</a>
<main class="auth-layout">
    <section class="auth-story" aria-labelledby="welcome-title">
        <a class="brand auth-brand" href="login.php"><span class="brand-mark"><svg width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 4c-8-1-15 2-15 8a7 7 0 0 0 7 7c6 0 9-7 8-15Z"/><path d="m4 21 11-11M8 17v-5m0 5h5"/></svg></span><span>Green Office<small>Energy monitoring, made clear.</small></span></a>
        <div class="auth-intro"><span class="badge badge-green">A BETTER OFFICE STARTS HERE</span><h1 id="welcome-title">Small changes.<br>Shared impact.</h1><p>Understand your office energy, explore potential savings and turn ideas into action.</p></div>
        <div class="auth-role-grid">
            <article class="auth-role-card"><h2>Administrator</h2><p>Manage readings, rooms, equipment, targets and office accounts.</p></article>
            <article class="auth-role-card"><h2>Office user</h2><p>View office data and reports, explore savings and manage your own actions.</p></article>
        </div>
        <p class="auth-footer">One office. Clear responsibilities. Every watt matters.</p>
    </section>
    <section class="auth-card" aria-labelledby="signin-title">
        <div class="section-heading"><div><h2 id="signin-title">Welcome back</h2><p>Sign in to <?= h($loginSettings['office_name']) ?>.</p></div></div>
        <?php if ($flash): ?><div class="notice <?= h($flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>"><?= h($flash['message']) ?></div><?php endif; ?>
        <form id="login-form" class="stack-form" method="post" action="endpoint/auth.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="login">
            <label for="login-email">Email address<input id="login-email" name="email" type="email" maxlength="254" autocomplete="username" inputmode="email" value="<?= h($loginEmail) ?>" required autofocus></label>
            <label for="login-password">Password<input id="login-password" name="password" type="password" autocomplete="current-password" required></label>
            <button class="button primary" type="submit">Sign in →</button>
            <p class="method-note">Your account opens its assigned panel. Ask your office administrator if you need an account or a password reset.</p>
            <p class="method-note"><a href="register.php">Create a new account</a></p>
        </form>
    </section>
</main>
</body>
</html>
