<?php
if (!isset($currentUser, $isAdmin)) {
    http_response_code(404);
    exit;
}
?>
<section class="page-heading"><div><h1>My profile</h1><p>Your office account and sign-in details.</p></div><span class="badge badge-green"><?= $isAdmin ? 'ADMINISTRATOR' : 'OFFICE USER' ?></span></section>
<section class="panel settings-panel"><div class="section-heading"><div><h2><?= h($currentUser['name']) ?></h2><p><?= h($currentUser['email']) ?></p></div></div><p class="method-note"><?= $isAdmin ? 'Your administrator account can manage office data, actions, settings and accounts.' : 'Your account can view office energy data and reports, explore savings and manage the actions you create.' ?></p></section>
<section class="panel settings-panel" aria-labelledby="password-title"><div class="section-heading"><div><h2 id="password-title">Change password</h2><p>Confirm your current password to choose a new one. Use at least 12 characters, maximum 72 bytes.</p></div></div>
<form id="password-form" class="stack-form" method="post" action="endpoint/auth.php">
    <?= csrf_field() ?><input type="hidden" name="action" value="change_password">
    <label for="current-password">Current password<input id="current-password" name="current_password" type="password" autocomplete="current-password" required></label>
    <div class="form-grid"><label for="new-password">New password<input id="new-password" name="new_password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label><label for="confirm-password">Confirm new password<input id="confirm-password" name="confirm_password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label></div>
    <button class="button primary" type="submit">Update password <?= office_icon('check') ?></button>
</form></section>
