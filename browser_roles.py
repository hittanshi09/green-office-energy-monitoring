#!/usr/bin/env python3
"""Role UI regression on the isolated seeded demo; temporary account removed on success."""
import json, os, pathlib, subprocess

ROOT = pathlib.Path(__file__).resolve().parents[1]
B = os.environ.get('GSTACK_BROWSE', str(pathlib.Path.home()/'.claude/skills/gstack/browse/dist/browse'))
URL = 'http://127.0.0.1:8085/'
REPORT = ROOT/'.gstack/qa-reports'
EMAIL = 'qa.browser@greenoffice.test'
PASSWORD = 'GreenBrowser!2026'
checks = []

def run(*args):
    r = subprocess.run([B, *args], cwd=ROOT, text=True, capture_output=True, timeout=35)
    if r.returncode:
        raise AssertionError(f'{args[0]} failed: {r.stderr or r.stdout}')
    return r.stdout.strip()

def check(label, expression):
    assert run('js', f'Boolean({expression})') == 'true', label
    checks.append(label)
    print('PASS', label, flush=True)

def contains(text):
    return 'document.body.innerText.includes('+json.dumps(text)+')'

def goto(path):
    run('goto', URL+path)

def fields(values):
    for selector, value in values.items():
        run('fill', selector, value)

def signout():
    if run('js', "Boolean(document.querySelector('.logout-form button'))") == 'true':
        run('click', '.logout-form button')

def login(email, password):
    goto('login.php')
    fields({'#login-email':email, '#login-password':password})
    run('click', '#login-form button[type=submit]')

def capture(name):
    run('screenshot', str(REPORT/'screenshots'/name))

def layout(name):
    for suffix, size in [('desktop','1440x1000'),('mobile','375x812')]:
        run('viewport',size)
        check(name+' '+suffix+' fits viewport', 'document.documentElement.scrollWidth <= innerWidth + 1')
        if suffix == 'mobile' and name != 'login':
            check(name+' mobile navigation fully visible', "[...document.querySelectorAll('.sidebar nav a')].every(a => { const r=a.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth + 1; })")
        capture('roles-'+name+'-'+suffix+'.png')
    run('viewport','1440x1000')

goto('index.php'); signout()
run('console','--clear')
goto('user.php')
check('anonymous panel redirects to login', "location.pathname === '/login.php'")
layout('login')
login('user@greenoffice.test', 'wrong-password-example')
check('wrong password stays on sign-in with alert', "location.pathname === '/login.php' && !!document.querySelector('[role=alert]')")
check('failed sign-in never repopulates password', "document.querySelector('#login-password').value === ''")
login('admin@greenoffice.test','GreenAdmin!2026')
check('admin arrives in admin panel', "location.pathname === '/admin.php' && document.body.innerText.includes('Admin panel')")
run('click', 'a[href="?page=users"]')
check('account management navigation works', "location.search.includes('page=users') && !!document.querySelector('#account-form')")
check('temporary account not already present', '!'+contains(EMAIL))
fields({'#account-name':'QA Browser Member','#account-email':EMAIL,'#account-password':PASSWORD})
run('select','#account-role','user')
run('click','#account-form button[type=submit]')
check('admin creates user through panel', contains(EMAIL))
run('click', 'tr:has-text("'+EMAIL+'") a')
fields({'#account-name':'QA Office Member'})
run('click','#account-form button[type=submit]')
check('admin edits user through panel',contains('QA Office Member'))
layout('accounts')
goto('admin.php?page=profile'); layout('admin-profile')
signout()
check('logout returns to login',"location.pathname === '/login.php'")
login(EMAIL,PASSWORD)
check('user arrives in user panel',"location.pathname === '/user.php' && document.body.innerText.includes('User panel')")
check('user sees shared office totals',contains('5,050.0'))
check('user has no reading/import forms or admin links',"!document.querySelector('#reading-form') && !document.querySelector('#import-form') && !document.querySelector('a[href=\"?page=settings\"]') && !document.querySelector('a[href=\"?page=users\"]')")
layout('user-overview')
run('click','a[href="?page=equipment"]')
check('equipment is read-only for user',"!document.querySelector('#equipment-form') && !document.querySelector('#room-form') && !document.querySelector('form[data-confirm]')")
run('click','a[href="?page=reports"]')
check('user can open reports',contains('Office energy report'))
run('download',URL+'endpoint/export.php',str(REPORT/'user-export.csv'))
assert len((REPORT/'user-export.csv').read_text().strip().splitlines()) == 8
checks.append('authenticated user exports seven readings')
goto('admin.php')
check('direct admin panel denied to user',contains('Administrator access required'))
goto('user.php?page=settings')
check('direct settings page denied to user',contains('Administrator access required'))
goto('user.php?page=actions&edit_action=1')
check('foreign action edit page denied',contains('Action unavailable'))
# Expected HTTP403/404 navigations above create browser resource errors.
run('console','--clear')
goto('user.php?page=actions')
check('new user has no office-owned editable actions', '!'+contains('Enable computer sleep schedules'))
goto('user.php?page=simulator')
check('user action owner bound visibly to account',"document.querySelector('#scenario-owner').readOnly && document.querySelector('#scenario-owner').value === 'QA Office Member'")
fields({'#scenario-title':'QA Personal LED action'})
run('click','#save-scenario')
check('user saves own scenario action',contains('QA Personal LED action')+' && '+contains('179.5'))
run('click','.action-row:has-text("QA Personal LED action") a')
fields({'#action-name':'QA Personal LED completed','#completed-date':'2026-10-08'})
run('select','#action-status','completed')
run('click','#action-form button[type=submit]')
check('user completes own action',contains('QA Personal LED completed')+' && '+contains('Completed'))
layout('user-actions')
run('dialog-accept')
run('click','.action-row:has-text("QA Personal LED completed") button')
check('user deletes own action','!'+contains('QA Personal LED completed'))
goto('user.php?page=profile'); layout('user-profile')
fields({'#current-password':PASSWORD,'#new-password':'GreenChanged!2026','#confirm-password':'GreenChanged!2026'})
run('click','#password-form button[type=submit]')
check('password change succeeds',"!document.querySelector('[role=alert]') && document.body.innerText.toLowerCase().includes('password')")
signout()
login(EMAIL,'GreenChanged!2026')
check('new password signs in successfully',"location.pathname === '/user.php'")
signout()
login('admin@greenoffice.test','GreenAdmin!2026')
goto('admin.php?page=users')
run('click','tr:has-text("'+EMAIL+'") a')
run('select','#account-active','0')
run('click','#account-form button[type=submit]')
check('inactive status visible',"[...document.querySelectorAll('tbody tr')].some(row => row.innerText.includes("+json.dumps(EMAIL)+") && row.innerText.includes('Inactive'))")
signout()
login(EMAIL,'GreenChanged!2026')
check('inactive account cannot sign in',"location.pathname === '/login.php' && !!document.querySelector('[role=alert]')")
login('admin@greenoffice.test','GreenAdmin!2026')
assert '(no console errors)' in run('console','--errors')
checks.append('no unexpected browser console errors')
# Remove only the exact temporary account in the isolated demo after its actions are removed.
subprocess.run(['mysql','--protocol=TCP','-h127.0.0.1','-P3307','-u','root','green_office_portfolio','-e',"DELETE FROM users WHERE email='qa.browser@greenoffice.test' AND name='QA Office Member' AND role='user' AND active=0"],check=True,capture_output=True)
goto('admin.php?page=users')
check('temporary account cleaned','!'+contains(EMAIL))
goto('admin.php')
check('shared energy baseline preserved',contains('5,050.0'))
(REPORT/'role-browser-results.json').write_text(json.dumps({'passed':len(checks),'checks':checks},indent=2)+'\n')
print(f'PASS: {len(checks)} role browser checks',flush=True)
