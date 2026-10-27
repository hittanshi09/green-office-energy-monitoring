<?php
require_once __DIR__ . '/lib/bootstrap.php';
if (go_current_user($conn) !== null) {
    header('Location: index.php', true, 302);
    exit;
}
$flash = go_get_flash();
$oldName = go_old_action() === 'register' ? go_old('name') : '';
$oldEmail = go_old_action() === 'register' ? go_old('email') : '';
go_clear_old();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173f35">
    <title>Create account · Green Office</title>
    <link rel="icon" href="assets/leaf.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="auth-page">
<a class="skip-link" href="#register-form">Skip to registration</a>
<main class="auth-layout">
    <section class="auth-story" aria-labelledby="welcome-title">
        <a class="brand auth-brand" href="login.php"><span class="brand-mark"><svg width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 4c-8-1-15 2-15 8a7 7 0 0 0 7 7c6 0 9-7 8-15Z"/><path d="m4 21 11-11M8 17v-5m0 5h5"/></svg></span><span>Green Office<small>Energy monitoring, made clear.</small></span></a>
        <div class="auth-intro"><span class="badge badge-green">JOIN THE OFFICE</span><h1 id="welcome-title">Create your account.</h1><p>Register as an office user or request admin access from the office administrator.</p></div>
        <div class="auth-role-grid">
            <article class="auth-role-card"><h2>Office user</h2><p>View office energy data and keep track of your own actions.</p></article>
            <article class="auth-role-card"><h2>Administrator</h2><p>Manage data, settings, actions and accounts once granted.</p></article>
        </div>
        <p class="auth-footer">One office. Clear responsibilities. Every watt matters.</p>
    </section>
    <section class="auth-card" aria-labelledby="register-title">
        <div class="section-heading"><div><h2 id="register-title">Create account</h2><p>Sign up for a new Green Office access.</p></div></div>
        <?php if ($flash): ?><div class="notice <?= h($flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>"><?= h($flash['message']) ?></div><?php endif; ?>
        <form id="register-form" class="stack-form" method="post" action="endpoint/register.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="register">
            <label for="register-name">Full name<input id="register-name" name="name" type="text" maxlength="100" value="<?= h($oldName) ?>" required></label>
            <label for="register-email">Email address<input id="register-email" name="email" type="email" maxlength="254" autocomplete="username" inputmode="email" value="<?= h($oldEmail) ?>" required></label>
            <label for="register-password">Password<input id="register-password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label>
            <label for="register-confirm">Confirm password<input id="register-confirm" name="confirm_password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label>
            <button class="button primary" type="submit">Create account →</button>
            <p class="method-note">Administrator accounts must be granted by the office admin. New users are created as office users by default.</p>
            <p class="method-note"><a href="login.php">Back to sign in</a></p>
        </form>
    </section>
</main>
</body>
</html>
