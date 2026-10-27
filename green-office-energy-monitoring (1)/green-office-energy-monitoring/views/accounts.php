<?php
if (!isset($currentUser, $isAdmin) || !$isAdmin) {
    http_response_code(404);
    exit;
}
try {
    $accounts = $conn->query('SELECT user_id, name, email, role, active FROM users ORDER BY name, user_id')->fetchAll();
} catch (PDOException $e) {
    error_log('Green Office account list unavailable: ' . $e->getMessage());
    echo '<div class="notice error" role="alert">Account management is unavailable. Ask the office administrator to check account setup.</div>';
    return;
}
$accountForm = $oldAction === 'save_user' ? $old : edit_record($accounts, 'user_id', 'edit_user');
$isEditingAccount = isset($accountForm['user_id']) && (string) $accountForm['user_id'] !== '';
?>
<section class="page-heading"><div><h1>Account management</h1><p>Give each person the access they need to contribute.</p></div><a class="button primary" href="?page=users#account-form"><?= office_icon('plus') ?> Add account</a></section>
<section class="panel"><div class="section-heading"><div><h2>Office accounts <span class="badge"><?= count($accounts) ?></span></h2><p>Administrators manage office data. Users explore energy data and manage their own saving actions.</p></div></div><div class="table-scroll"><table><thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Access</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>
<?php foreach ($accounts as $account): ?>
<tr><td class="strong"><?= h($account['name']) ?><?php if ((int) $account['user_id'] === (int) $currentUser['user_id']): ?><small class="cell-note">Your account</small><?php endif; ?></td><td><?= h($account['email']) ?></td><td><span class="badge <?= $account['role'] === 'admin' ? 'badge-green' : '' ?>"><?= $account['role'] === 'admin' ? 'Administrator' : 'Office user' ?></span></td><td><span class="badge <?= (int) $account['active'] === 1 ? 'badge-green' : 'badge-amber' ?>"><?= (int) $account['active'] === 1 ? 'Active' : 'Inactive' ?></span></td><td><a class="text-button" href="?page=users&amp;edit_user=<?= (int) $account['user_id'] ?>#account-form">Edit account<span class="sr-only"> <?= h($account['name']) ?></span></a></td></tr>
<?php endforeach; if (!$accounts): ?><tr><td colspan="5" class="empty-cell">No office accounts available.</td></tr><?php endif; ?>
</tbody></table></div><p class="table-footnote"><span class="mobile-hint">Swipe the table sideways to see role, access and edit controls. </span>Deactivate access to prevent sign-in while retaining the person's action history.</p></section>
<section class="panel settings-panel" aria-labelledby="account-form-title"><div class="section-heading"><div><h2 id="account-form-title"><?= $isEditingAccount ? 'Edit account' : 'Add an office account' ?></h2><p><?= $isEditingAccount ? 'Update account details, access or password.' : 'Create an account and share its sign-in details with the person.' ?></p></div></div>
<form id="account-form" class="stack-form" method="post" action="endpoint/accounts.php">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_user"><input type="hidden" name="user_id" value="<?= h($accountForm['user_id'] ?? '') ?>">
    <div class="form-grid">
        <label for="account-name">Name<input id="account-name" name="name" maxlength="100" autocomplete="off" value="<?= h($accountForm['name'] ?? '') ?>" required></label>
        <label for="account-email">Email address<input id="account-email" name="email" type="email" maxlength="254" autocomplete="off" inputmode="email" value="<?= h($accountForm['email'] ?? '') ?>" required></label>
        <label for="account-role">Role<select id="account-role" name="role"><option value="user" <?= ($accountForm['role'] ?? 'user') === 'user' ? 'selected' : '' ?>>Office user</option><option value="admin" <?= ($accountForm['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Administrator</option></select></label>
        <label for="account-active">Account access<select id="account-active" name="active"><option value="1" <?= (string) ($accountForm['active'] ?? '1') === '1' ? 'selected' : '' ?>>Active</option><option value="0" <?= (string) ($accountForm['active'] ?? '1') === '0' ? 'selected' : '' ?>>Inactive</option></select></label>
        <label for="account-password" class="full"><?= $isEditingAccount ? 'New password (optional)' : 'Initial password' ?><input id="account-password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" <?= $isEditingAccount ? '' : 'required' ?>><small><?= $isEditingAccount ? 'Leave empty to keep the current password. New passwords: at least 12 characters, maximum 72 bytes.' : 'Use at least 12 characters, maximum 72 bytes. The person can change this after signing in.' ?></small></label>
    </div>
    <div class="form-actions"><button class="button primary" type="submit"><?= $isEditingAccount ? 'Save account changes' : 'Create account' ?></button><?php if ($isEditingAccount): ?><a class="text-button" href="?page=users#account-form">Cancel edit</a><?php endif; ?></div>
</form></section>
