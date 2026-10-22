"""Authentication and permission checks used by integration.py's isolated fixture."""
import subprocess
import urllib.parse

ADMIN_EMAIL = 'admin@greenoffice.test'
ADMIN_PASSWORD = 'GreenAdmin!2026'
USER_EMAIL = 'user@greenoffice.test'
USER_PASSWORD = 'GreenUser!2026'


def path_of(headers, request_path='/endpoint/auth.php'):
    return urllib.parse.urlsplit(urllib.parse.urljoin('http://127.0.0.1:8086' + request_path, headers['Location'])).path


def cookies(client):
    return tuple((cookie.name, cookie.value) for cookie in client.test_cookies)


def password_hash(context, password):
    result = subprocess.run([context['php'], '-r', 'echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);'],
                            input=password, text=True, capture_output=True, check=True)
    return result.stdout


def prepare_admin(context):
    sql, expect = context['sql'], context['expect']
    expect('manual migration never creates demo accounts', sql('SELECT COUNT(*) FROM users'), '0')
    bootstrap_admin(context)
    before = sql('SELECT user_id,password_hash,role,active,auth_version FROM users ORDER BY user_id')
    result = bootstrap_admin(context, success=False)
    expect('bootstrap cannot replace an existing active admin', result.returncode != 0)
    expect('repeated bootstrap preserves account credentials', sql('SELECT user_id,password_hash,role,active,auth_version FROM users ORDER BY user_id') == before)
    expect('legacy actions gain nullable ownership', sql("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='actions' AND COLUMN_NAME='created_by'"), 'YES')


def bootstrap_admin(context, success=True):
    result = subprocess.run([context['php'], 'scripts/create-admin.php'], cwd=context['root'], env=context['env'],
                            input=f'Integration admin\n{ADMIN_EMAIL}\n{ADMIN_PASSWORD}\n{ADMIN_PASSWORD}\n',
                            text=True, capture_output=True)
    if success:
        context['expect']('manual first-admin bootstrap succeeds', result.returncode, 0)
        context['expect']('manual bootstrap creates one active admin', context['sql']("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1"), '1')
    context['expect']('bootstrap does not echo password', ADMIN_PASSWORD not in result.stdout + result.stderr)
    return result


def login(context, client, email, password, role):
    request, session, expect = context['request'], context['session'], context['expect']
    before = session(client)
    before_cookies = cookies(client)
    status, headers, _ = request('/endpoint/auth.php', {'action': 'login', 'csrf': before['csrf'],
                                                       'email': email, 'password': password}, client=client)
    expect('valid login redirects', status, 303)
    expect('valid login redirect local', path_of(headers) in ['/index.php', '/admin.php', '/user.php'])
    cookie_headers = ';'.join(headers.get_all('Set-Cookie', []))
    expect('authenticated cookie stays HttpOnly', 'httponly' in cookie_headers.lower())
    expect('authenticated cookie keeps SameSite policy', 'samesite=lax' in cookie_headers.lower())
    after = session(client)
    expect('login establishes correct role', after['user']['role'] if after['user'] else None, role)
    expect('login rotates session cookie', cookies(client) != before_cookies)
    expect('login rotates CSRF token', after['csrf'] != before['csrf'])
    status, headers, _ = request('/index.php', client=client)
    expect('role dispatcher redirects', status, 302)
    expect('role dispatcher selects correct panel', path_of(headers, '/index.php'), '/' + role + '.php')
    return after['user']


def run_auth_checks(context):
    request, session, expect, sql = (context[key] for key in ['request', 'session', 'expect', 'sql'])
    admin = context['admin']
    make_client = context['make_client']
    admin_id = sql("SELECT user_id FROM users WHERE email='admin@greenoffice.test'")

    def anonymous_block(path, fields=None, client=None):
        status, headers, _ = request(path, fields, client=client)
        expect('anonymous ' + path + ' blocked', status, 303)
        expect('anonymous ' + path + ' redirects to login', path_of(headers), '/login.php')

    def denied(path, fields=None, client=None):
        status, _, _ = request(path, fields, client=client)
        expect('user forbidden ' + path, status, 403)

    def save(client, action, fields=None, success=True, path='/endpoint/save.php'):
        fields = dict(fields or {}, action=action, csrf=session(client)['csrf'], return_page='actions')
        status, headers, _ = request(path, fields, client=client)
        expect(action + ' authenticated redirect', status, 303)
        expect(action + ' redirect stays in application', path_of(headers) in ['/index.php', '/admin.php', '/user.php'])
        state = session(client)
        expect(action + ' expected outcome', state['flash']['type'], 'success' if success else 'error')
        expect(action + ' never retains password inputs', not any('password' in key or key == 'csrf' for key in state['old_keys']))
        return state

    def account(fields, success=True):
        return save(admin, 'save_user', fields, success, '/endpoint/accounts.php')

    def account_data(user_id, name, email, role='user', active='1', password=''):
        return {'user_id': user_id, 'name': name, 'email': email, 'role': role, 'active': active, 'password': password}

    def snapshot():
        queries = ['SELECT * FROM tbl_reading ORDER BY reading_id', 'SELECT * FROM rooms ORDER BY room_id',
                   'SELECT * FROM equipment ORDER BY equipment_id', 'SELECT * FROM actions ORDER BY action_id',
                   'SELECT * FROM office_settings ORDER BY setting_key', 'SELECT * FROM users ORDER BY user_id']
        return tuple(sql(query) for query in queries)

    anonymous = make_client()
    anon_csrf = session(anonymous)['csrf']
    for path in ['/index.php', '/admin.php', '/user.php', '/endpoint/export.php']:
        anonymous_block(path, client=anonymous)
    before = snapshot()
    anonymous_block('/endpoint/save.php', {'action': 'add_room', 'csrf': anon_csrf, 'name': 'Unauthenticated room', 'area': '10'}, anonymous)
    anonymous_block('/endpoint/accounts.php', {'action': 'save_user', 'csrf': anon_csrf}, anonymous)
    expect('anonymous requests preserve all data', snapshot() == before)
    expect('login page available anonymously', request('/login.php', client=anonymous)[0], 200)
    expect('login requires POST', request('/endpoint/auth.php?action=login', client=anonymous)[0], 405)
    expect('login missing CSRF rejected', request('/endpoint/auth.php', {'action': 'login', 'email': ADMIN_EMAIL, 'password': ADMIN_PASSWORD}, client=anonymous)[0], 403)
    expect('login CSRF from another session rejected', request('/endpoint/auth.php', {'action': 'login', 'csrf': session(admin)['csrf'], 'email': ADMIN_EMAIL, 'password': ADMIN_PASSWORD}, client=anonymous)[0], 403)
    expect('failed CSRF creates no authenticated session', session(anonymous)['user'], None)

    expect('account management requires CSRF', request('/endpoint/accounts.php', dict(account_data('', 'Blocked account', 'no-csrf@greenoffice.test', password=USER_PASSWORD), action='save_user'), client=admin)[0], 403)
    account(account_data('', 'Office member', USER_EMAIL, password=USER_PASSWORD))
    user_id = sql("SELECT user_id FROM users WHERE email='user@greenoffice.test'")
    expect('account stores hash rather than password', sql(f"SELECT password_hash <> '{USER_PASSWORD}' FROM users WHERE user_id={user_id}"), '1')
    account(account_data('', 'Duplicate member', USER_EMAIL, password=USER_PASSWORD), False)
    account(account_data('', 'Bad role', 'invalid-role@greenoffice.test', role='superadmin', password=USER_PASSWORD), False)
    account(account_data('', 'Weak password', 'weak@greenoffice.test', password='short'), False)
    account(account_data('', 'Oversize password', 'oversize@greenoffice.test', password='A1!' + 'x' * 70), False)
    account(account_data('', 'Short Unicode password', 'short-unicode@greenoffice.test', password='é' * 6), False)
    account(account_data('', 'Unicode member', 'unicode@greenoffice.test', password='é' * 12))
    unicode_client = make_client()
    login(context, unicode_client, 'unicode@greenoffice.test', 'é' * 12, 'user')
    user = make_client()
    login(context, user, USER_EMAIL, USER_PASSWORD, 'user')
    for path in ['/admin.php', '/admin.php?page=settings', '/admin.php?page=users', '/user.php?page=settings', '/user.php?page=users']:
        denied(path, client=user)
    for page in ['overview', 'equipment', 'simulator', 'actions', 'reports']:
        expect('user reads ' + page, request('/user.php?page=' + page, client=user)[0], 200)
    expect('authenticated user can export readings', request('/endpoint/export.php', client=user)[0], 200)

    reading_id = sql('SELECT MIN(reading_id) FROM tbl_reading')
    room_id = sql('SELECT MIN(room_id) FROM rooms')
    equipment_id = sql('SELECT MIN(equipment_id) FROM equipment')
    admin_mutations = {
        'save_reading': {'reading_date': '2028-01-01', 'meter_reading': '99999'},
        'delete_reading': {'id': reading_id},
        'import_readings': {},
        'save_settings': {'office_name': 'Unauthorized replacement'},
        'add_room': {'name': 'Unauthorized room', 'area': '10'},
        'delete_room': {'id': room_id},
        'save_equipment': {'room_id': room_id, 'name': 'Unauthorized equipment', 'watts': '40', 'quantity': '1', 'hours_per_day': '8', 'days_per_month': '22'},
        'delete_equipment': {'id': equipment_id},
        'unknown_action': {},
    }
    before = snapshot()
    for action, fields in admin_mutations.items():
        denied('/endpoint/save.php', dict(fields, action=action, csrf=session(user)['csrf']), user)
    denied('/endpoint/add-reading.php', {'reading_date': '2028-01-01', 'meter_reading': '99999', 'csrf': session(user)['csrf']}, user)
    denied('/endpoint/delete-reading.php', {'id': reading_id, 'csrf': session(user)['csrf']}, user)
    denied('/endpoint/accounts.php', dict(account_data('', 'Forged admin', 'forged@greenoffice.test', role='admin', password=ADMIN_PASSWORD), action='save_user', csrf=session(user)['csrf']), user)
    expect('all forbidden mutations preserve all data', snapshot() == before)

    proposal = {'title': 'Owned action', 'owner': 'Impersonated administrator', 'due_date': '2026-10-20',
                'status': 'open', 'estimated_kwh': '12.125', 'notes': 'Ownership integration check', 'created_by': admin_id}
    save(user, 'save_action', proposal)
    owned_id = sql('SELECT MAX(action_id) FROM actions')
    expect('created_by comes from authenticated user', sql('SELECT created_by FROM actions WHERE action_id=' + owned_id), user_id)
    expect('user owner label cannot impersonate another person', sql('SELECT owner FROM actions WHERE action_id=' + owned_id), 'Office member')
    save(user, 'save_action', dict(proposal, action_id=owned_id, title='Owned action edited', created_by='2147483647'))
    expect('owner remains unchanged on edit', sql('SELECT created_by FROM actions WHERE action_id=' + owned_id), user_id)
    expect('user can edit own action', sql('SELECT title FROM actions WHERE action_id=' + owned_id), 'Owned action edited')
    save(admin, 'save_action', dict(proposal, title='Admin-owned action', created_by=user_id))
    admin_action = sql('SELECT MAX(action_id) FROM actions')
    expect('admin creation also ignores spoofed created_by', sql('SELECT created_by FROM actions WHERE action_id=' + admin_action), admin_id)
    legacy_action = sql('SELECT MIN(action_id) FROM actions WHERE created_by IS NULL')
    expect('legacy action fixture remains available', legacy_action != 'NULL')
    before = snapshot()
    for action_id in [admin_action, legacy_action]:
        denied('/endpoint/save.php', dict(proposal, action='save_action', action_id=action_id, csrf=session(user)['csrf']), user)
        denied('/endpoint/save.php', {'action': 'delete_action', 'id': action_id, 'csrf': session(user)['csrf']}, user)
    expect('forbidden action ownership changes preserve all data', snapshot() == before)
    save(admin, 'save_action', dict(proposal, action_id=owned_id, title='Admin-reviewed member action', created_by=admin_id))
    expect('admin edit preserves original action owner', sql('SELECT created_by FROM actions WHERE action_id=' + owned_id), user_id)
    save(admin, 'save_action', dict(proposal, action_id=legacy_action, title='Reviewed legacy action'))
    expect('admin edit leaves legacy ownership unassigned', sql('SELECT created_by IS NULL FROM actions WHERE action_id=' + legacy_action), '1')
    save(user, 'delete_action', {'id': owned_id})
    expect('user can delete own action', sql('SELECT COUNT(*) FROM actions WHERE action_id=' + owned_id), '0')

    subject_email, subject_password = 'session-subject@greenoffice.test', 'SessionUser!2026'
    subject_fields = account_data('', 'Session subject', subject_email, password=subject_password)
    account(subject_fields)
    subject_id = sql("SELECT user_id FROM users WHERE email='session-subject@greenoffice.test'")
    subject_fields.update(user_id=subject_id, password='')
    subject, subject_other = make_client(), make_client()
    login(context, subject, subject_email, subject_password, 'user')
    login(context, subject_other, subject_email, subject_password, 'user')
    account(dict(subject_fields, active='0'))
    anonymous_block('/user.php', client=subject)
    anonymous_block('/endpoint/save.php', dict(proposal, action='save_action', csrf=session(subject_other)['csrf']), subject_other)
    expect('disabled session revoked immediately', session(subject)['user'], None)
    status, _, _ = request('/endpoint/auth.php', {'action': 'login', 'csrf': session(subject)['csrf'], 'email': subject_email, 'password': subject_password}, client=subject)
    expect('disabled account cannot sign in again', session(subject)['user'], None)
    account(subject_fields)
    anonymous_block('/endpoint/export.php', client=subject)
    login(context, subject, subject_email, subject_password, 'user')
    sql('UPDATE users SET auth_version=auth_version+1 WHERE user_id=' + subject_id)
    anonymous_block('/user.php', client=subject)
    expect('explicit auth version revokes session', session(subject)['user'], None)

    secondary_email, secondary_password = 'secondary-admin@greenoffice.test', 'SecondaryAdmin!2026'
    secondary_fields = account_data('', 'Secondary administrator', secondary_email, role='admin', password=secondary_password)
    account(secondary_fields)
    secondary_id = sql("SELECT user_id FROM users WHERE email='secondary-admin@greenoffice.test'")
    secondary = make_client()
    login(context, secondary, secondary_email, secondary_password, 'admin')
    account(dict(secondary_fields, user_id=secondary_id, role='user', password=''))
    anonymous_block('/admin.php', client=secondary)
    login(context, secondary, secondary_email, secondary_password, 'user')
    denied('/admin.php', client=secondary)
    admin_fields = account_data(admin_id, 'Integration admin', ADMIN_EMAIL, role='admin')
    account(dict(admin_fields, active='0'), False)
    expect('last active admin cannot be disabled', sql('SELECT active FROM users WHERE user_id=' + admin_id), '1')
    account(dict(admin_fields, role='user'), False)
    expect('last active admin cannot be demoted', sql('SELECT role FROM users WHERE user_id=' + admin_id), 'admin')
    expect('failed self-demotion retains working admin session', session(admin)['user']['role'], 'admin')

    login(context, subject, subject_email, subject_password, 'user')
    login(context, subject_other, subject_email, subject_password, 'user')
    expect('password change requires CSRF', request('/endpoint/auth.php', {'action': 'change_password', 'current_password': subject_password, 'new_password': 'Unauthorized!2026', 'confirm_password': 'Unauthorized!2026'}, client=subject)[0], 403)
    saved_hash = sql('SELECT password_hash FROM users WHERE user_id=' + subject_id)
    changed_password = 'é' * 12
    password_fields = {'current_password': subject_password, 'new_password': changed_password, 'confirm_password': changed_password}
    save(subject, 'change_password', dict(password_fields, current_password='IncorrectPassword!'), False, '/endpoint/auth.php')
    save(subject, 'change_password', dict(password_fields, confirm_password='DifferentPassword!'), False, '/endpoint/auth.php')
    save(subject, 'change_password', dict(password_fields, new_password='short', confirm_password='short'), False, '/endpoint/auth.php')
    save(subject, 'change_password', dict(password_fields, new_password='é' * 6, confirm_password='é' * 6), False, '/endpoint/auth.php')
    save(subject, 'change_password', dict(password_fields, new_password='A1!' + 'x' * 70, confirm_password='A1!' + 'x' * 70), False, '/endpoint/auth.php')
    expect('invalid password changes preserve hash', sql('SELECT password_hash FROM users WHERE user_id=' + subject_id) == saved_hash)
    before_token, before_cookie = session(subject)['csrf'], cookies(subject)
    save(subject, 'change_password', password_fields, True, '/endpoint/auth.php')
    expect('password change replaces hash', sql('SELECT password_hash FROM users WHERE user_id=' + subject_id) != saved_hash)
    expect('password change rotates CSRF', session(subject)['csrf'] != before_token)
    expect('password change rotates session', cookies(subject) != before_cookie)
    expect('current password-changing session remains authenticated', str(session(subject)['user']['user_id']), subject_id)
    anonymous_block('/user.php', client=subject_other)
    login(context, subject_other, subject_email, changed_password, 'user')
    reset_password = 'AdministratorReset!2026'
    account(dict(subject_fields, password=reset_password))
    anonymous_block('/user.php', client=subject)
    anonymous_block('/user.php', client=subject_other)
    login(context, subject, subject_email, reset_password, 'user')
    expect('logout requires POST', request('/endpoint/auth.php?action=logout', client=subject)[0], 405)
    expect('logout missing CSRF rejected', request('/endpoint/auth.php', {'action': 'logout'}, client=subject)[0], 403)
    expect('rejected logout preserves session', str(session(subject)['user']['user_id']), subject_id)
    status, headers, _ = request('/endpoint/auth.php', {'action': 'logout', 'csrf': session(subject)['csrf']}, client=subject)
    expect('logout redirects', status, 303)
    expect('logout returns to login', path_of(headers), '/login.php')
    expect('logout clears authenticated identity', session(subject)['user'], None)
    anonymous_block('/endpoint/export.php', client=subject)

    hashes_before = sql('SELECT user_id,password_hash,role,active,auth_version FROM users ORDER BY user_id')
    context['setup'](True)
    expect('repeated migration preserves accounts, hashes and roles', sql('SELECT user_id,password_hash,role,active,auth_version FROM users ORDER BY user_id') == hashes_before)
    expect('migration preserves action ownership', sql('SELECT created_by FROM actions WHERE action_id=' + admin_action), admin_id)

    attacker = make_client()
    first_message = None
    for attempt in range(5):
        status, headers, _ = request('/endpoint/auth.php', {'action': 'login', 'csrf': session(attacker)['csrf'],
                                                          'email': subject_email, 'password': 'IncorrectPassword!2026'}, client=attacker)
        state = session(attacker)
        expect('invalid login never authenticates', state['user'], None)
        expect('invalid login redirects generically', status, 303)
        expect('invalid login returns to login form', path_of(headers), '/login.php')
        if first_message is None:
            first_message = state['flash']['message']
        expect('failed login response stays generic', state['flash']['message'], first_message)
    status, headers, _ = request('/endpoint/auth.php', {'action': 'login', 'csrf': session(attacker)['csrf'],
                                                       'email': subject_email, 'password': reset_password}, client=attacker)
    state = session(attacker)
    expect('throttle blocks correct credentials after repeated failures', state['user'], None)
    expect('throttle returns same generic error', state['flash']['message'], first_message)
    expect('throttled login stays on login page', path_of(headers), '/login.php')
    expect('failed attempts recorded persistently', int(sql('SELECT MAX(attempts) FROM auth_throttle')) >= 5)
    sql('UPDATE auth_throttle SET window_started=DATE_SUB(NOW(), INTERVAL 16 MINUTE)')
    login(context, attacker, subject_email, reset_password, 'user')


def run_seed_checks(context):
    sql, setup, expect = context['sql'], context['setup'], context['expect']
    database = context['database']
    sql(f'DROP DATABASE `{database}`; CREATE DATABASE `{database}` CHARACTER SET utf8mb4;', False)
    setup()
    expect('fresh manual office has no demo users', sql('SELECT COUNT(*) FROM users'), '0')
    expect('fresh manual office label', sql("SELECT setting_value FROM office_settings WHERE setting_key='data_mode'"), 'manual')
    setup(True)
    expect('explicit empty demo seeds two accounts', sql('SELECT COUNT(*) FROM users'), '2')
    expect('explicit demo account roles', sql('SELECT email,role,active FROM users ORDER BY email'),
           'admin@greenoffice.test\tadmin\t1\nuser@greenoffice.test\tuser\t1')
    demo_client = context['make_client']()
    login(context, demo_client, ADMIN_EMAIL, ADMIN_PASSWORD, 'admin')
    member_client = context['make_client']()
    login(context, member_client, USER_EMAIL, USER_PASSWORD, 'user')
    before = sql('SELECT user_id,password_hash,role,active,auth_version FROM users ORDER BY user_id')
    setup(True)
    expect('demo setup rerun never resets credentials', sql('SELECT user_id,password_hash,role,active,auth_version FROM users ORDER BY user_id') == before)
    replacement = password_hash(context, 'LocallyChanged!2026')
    sql(f"UPDATE users SET password_hash='{replacement}',active=0,auth_version=auth_version+1 WHERE email='user@greenoffice.test'")
    before = sql('SELECT user_id,password_hash,role,active,auth_version FROM users ORDER BY user_id')
    setup(True)
    expect('demo migration preserves changed passwords and disabled accounts', sql('SELECT user_id,password_hash,role,active,auth_version FROM users ORDER BY user_id') == before)
