#!/usr/bin/env python3
"""Replay backend checks using only Python stdlib, PHP and mysql CLI.

Recreates ONLY green_office_backend_test on isolated 127.0.0.1:3307.
Starts its own PHP server on 8086; terminates it even when an assertion fails.
Never connects to the normal MySQL port 3306 or the main demo database.
"""
import csv
import http.cookiejar
import io
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

from auth import prepare_admin, run_auth_checks, run_seed_checks

ROOT = Path(__file__).resolve().parents[1]
DB = 'green_office_backend_test'
BASE = 'http://127.0.0.1:8086'
ENV = dict(os.environ, GREEN_DB_HOST='127.0.0.1', GREEN_DB_PORT='3307',
           GREEN_DB_NAME=DB, GREEN_DB_USER='root', GREEN_DB_PASSWORD='')
ENV.pop('MYSQL_PWD', None)
MYSQL = shutil.which('mysql')
PHP = shutil.which('php')
if not MYSQL or not PHP:
    raise SystemExit('Install PHP with pdo_mysql and mysql CLI first.')
checks = []


def expect(label, got, wanted=True):
    if got != wanted:
        raise AssertionError(f'{label}: expected {wanted!r}, got {got!r}')
    checks.append(label)


def sql(query, database=True):
    args = [MYSQL, '--batch', '--skip-column-names', '-h127.0.0.1', '-P3307', '-uroot']
    if database:
        args.append(DB)
    result = subprocess.run([*args, '-e', query], env=ENV, text=True, capture_output=True, check=True)
    return result.stdout.strip()


def setup(demo=False):
    result = subprocess.run([PHP, 'scripts/setup.php', *(['--demo'] if demo else [])],
                            cwd=ROOT, env=ENV, text=True, capture_output=True, check=True)
    expect('setup has no PHP warnings', result.stderr, '')
    return result.stdout


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def make_client():
    jar = http.cookiejar.CookieJar()
    client = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPCookieProcessor(jar))
    client.test_cookies = jar
    return client


opener = make_client()


def request(path, fields=None, upload=None, client=None):
    headers = {}
    body = None
    if fields is not None:
        if upload is None:
            body = urllib.parse.urlencode(fields).encode()
            headers['Content-Type'] = 'application/x-www-form-urlencoded'
        else:
            boundary = 'GreenOfficeIntegrationBoundary'
            parts = []
            for key, value in fields.items():
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="readings_file"; filename="readings.csv"\r\nContent-Type: text/csv\r\n\r\n'.encode() + upload + b'\r\n')
            parts.append(f'--{boundary}--\r\n'.encode())
            body = b''.join(parts)
            headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
    req = urllib.request.Request(BASE + path, data=body, headers=headers)
    try:
        response = (client or opener).open(req, timeout=10)
    except urllib.error.HTTPError as error:
        response = error
    return response.code, response.headers, response.read().decode()


def session(client=None):
    status, _, body = request('/__integration_session', client=client)
    if status != 200:
        raise AssertionError('Test session endpoint unavailable: ' + body)
    return json.loads(body)


def post(action, fields=None, success=True, upload=None):
    fields = dict(fields or {}, action=action, csrf=session()['csrf'], return_page='overview')
    status, headers, _ = request('/endpoint/save.php', fields, upload)
    expect(action + ' redirects using 303', status, 303)
    expect(action + ' redirect stays local', headers['Location'], '../index.php?page=overview')
    state = session()
    expect(action + ' expected flash', state['flash']['type'], 'success' if success else 'error')
    expect(action + ' retained inputs exclude CSRF', 'csrf' not in state['old_keys'])
    if not success:
        expect(action + ' failed input retained', 'action' in state['old_keys'])
    return state


expect('isolated MySQL port verified', sql('SELECT @@port', False), '3307')
sql(f'DROP DATABASE IF EXISTS `{DB}`; CREATE DATABASE `{DB}` CHARACTER SET utf8mb4;', False)
sql('''CREATE TABLE tbl_reading (reading_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
reading_date DATE NOT NULL, meter_reading INT NOT NULL) ENGINE=InnoDB;
INSERT INTO tbl_reading VALUES (7,'2026-01-01',1000),(11,'2026-02-01',1100),
(15,'2026-02-01',1100),(22,'2026-03-01',1200);''')
legacy = sql('SELECT reading_id,reading_date,meter_reading FROM tbl_reading ORDER BY reading_id')
setup()
expect('legacy IDs and meter values preserved', sql('SELECT reading_id,reading_date,CAST(meter_reading AS SIGNED) FROM tbl_reading ORDER BY reading_id'), legacy)
expect('legacy decimal upgrade', sql("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tbl_reading' AND COLUMN_NAME='meter_reading'"), 'decimal(12,3)')
expect('legacy duplicate dates retained', sql('SELECT COUNT(*) FROM tbl_reading'), '4')
expect('legacy resets default zero', sql('SELECT SUM(meter_reset) FROM tbl_reading'), '0')
setup(True)
expect('demo seed never replaces legacy data', sql('SELECT COUNT(*) FROM tbl_reading'), '4')
expect('demo seed skips rooms when legacy data exists', sql('SELECT COUNT(*) FROM rooms'), '0')
context = dict(expect=expect, sql=sql, setup=setup, request=request, session=session,
               make_client=make_client, admin=opener, env=ENV, root=ROOT, php=PHP, database=DB)
prepare_admin(context)

with tempfile.TemporaryDirectory(prefix='green-office-http-test-') as temporary:
    router = Path(temporary) / 'router.php'
    bootstrap = str(ROOT / 'lib/bootstrap.php').replace('\\', '\\\\').replace("'", "\\'")
    router.write_text("<?php\nif (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__integration_session') {\n"
                      f"require '{bootstrap}';\n"
                      "$testUser = go_current_user($conn);\nheader('Content-Type: application/json');\n"
                      "echo json_encode(['csrf'=>$_SESSION['csrf'],'flash'=>go_get_flash(),'old_keys'=>array_keys($_SESSION['old']??[]), 'timezone'=>date_default_timezone_get(), 'user'=>$testUser ? array_intersect_key($testUser, array_flip(['user_id','name','email','role','active'])) : null]); return true;\n}\nreturn false;\n")
    log = open(Path(temporary) / 'php.log', 'w+')
    server = subprocess.Popen([PHP, '-d', 'display_errors=0', '-d', 'error_reporting=E_ALL', '-S', '127.0.0.1:8086', '-t', str(ROOT), str(router)],
                              cwd=ROOT, env=ENV, stdout=log, stderr=log)
    try:
        for attempt in range(100):
            if server.poll() is not None:
                raise RuntimeError('PHP server failed to start on 8086.')
            try:
                state = session()
                break
            except urllib.error.URLError:
                time.sleep(0.05)
        else:
            raise RuntimeError('PHP server readiness timeout.')
        expect('India timezone active', state['timezone'], 'Asia/Kolkata')
        status, headers, _ = request('/__integration_session')
        expect('session responses prevent caching', headers['Cache-Control'], 'no-store')
        expect('framing disabled', headers['X-Frame-Options'], 'DENY')
        from auth import login
        login(context, opener, 'admin@greenoffice.test', 'GreenAdmin!2026', 'admin')
        expect('GET deletion rejected', request('/endpoint/delete-reading.php?reading=7')[0], 405)
        expect('GET save rejected', request('/endpoint/save.php')[0], 405)
        expect('missing CSRF rejected', request('/endpoint/save.php', {'action': 'delete_reading', 'id': '7'})[0], 403)
        expect('wrong CSRF rejected', request('/endpoint/save.php', {'action': 'delete_reading', 'id': '7', 'csrf': 'wrong'})[0], 403)
        expect('rejected requests preserve data', sql('SELECT COUNT(*) FROM tbl_reading'), '4')
        expect('setup CLI inaccessible by browser', request('/scripts/setup.php')[0], 404)
        post('delete_reading', {'id': 15})
        expect('legacy duplicate can be corrected', sql('SELECT COUNT(*) FROM tbl_reading'), '3')
        setup()
        expect('unique date index added after correction', sql("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tbl_reading' AND INDEX_NAME='reading_date_unique' AND NON_UNIQUE=0"), '1')
        sql("UPDATE tbl_reading SET meter_reading=500 WHERE reading_id=22; INSERT INTO tbl_reading (reading_id,reading_date,meter_reading) VALUES (23,'2026-04-01',400)")
        post('save_reading', {'reading_id':22, 'reading_date':'2026-03-01', 'meter_reading':'1200'})
        expect('partial correction preserves unresolved legacy row', sql('SELECT meter_reading FROM tbl_reading WHERE reading_id=23'), '400.000')
        post('save_reading', {'reading_id':23, 'reading_date':'2026-04-01', 'meter_reading':'1300.125'})
        expect('decimal edit preserved', sql('SELECT meter_reading FROM tbl_reading WHERE reading_id=23'), '1300.125')
        sql('DELETE FROM tbl_reading; DELETE FROM office_settings; INSERT INTO office_settings VALUES (\'_readings_lock\',\'1\')')
        setup(True)
        counts = sql('SELECT (SELECT COUNT(*) FROM tbl_reading),(SELECT COUNT(*) FROM rooms),(SELECT COUNT(*) FROM equipment),(SELECT COUNT(*) FROM actions)')
        expect('demo seed complete', counts, '7\t3\t6\t3')
        setup(True)
        expect('setup and seed idempotent', sql('SELECT (SELECT COUNT(*) FROM tbl_reading),(SELECT COUNT(*) FROM rooms),(SELECT COUNT(*) FROM equipment),(SELECT COUNT(*) FROM actions)'), counts)
        expect('demo label stored', sql("SELECT setting_value FROM office_settings WHERE setting_key='data_mode'"), 'demo')
        post('save_reading', {'reading_date':'2026-11-01','meter_reading':'18000.125'})
        new_id = sql("SELECT reading_id FROM tbl_reading WHERE reading_date='2026-11-01'")
        expect('decimal add preserved', sql('SELECT meter_reading FROM tbl_reading WHERE reading_id=' + new_id), '18000.125')
        post('save_reading', {'reading_date':'2026-11-01','meter_reading':'18001'}, False)
        post('save_reading', {'reading_id':new_id,'reading_date':'2026-11-01','meter_reading':'1'}, False)
        expect('invalid edit preserves original value', sql('SELECT meter_reading FROM tbl_reading WHERE reading_id=' + new_id), '18000.125')
        post('save_reading', {'reading_date':'2026-02-30','meter_reading':'19000'}, False)
        post('save_reading', {'reading_date':'2025-01-01','meter_reading':'-10'}, False)
        post('save_reading', {'reading_date':'2026-12-01','meter_reading':'19000.1234'}, False)
        post('save_reading', {'reading_date':'2026-12-01','meter_reading':'25.5','meter_reset':'1'})
        reset_id = sql("SELECT reading_id FROM tbl_reading WHERE reading_date='2026-12-01'")
        post('save_reading', {'reading_date':'2027-01-01','meter_reading':'30'})
        after_reset_id = sql("SELECT reading_id FROM tbl_reading WHERE reading_date='2027-01-01'")
        post('delete_reading', {'id':reset_id}, False)
        expect('reset deletion protection preserves record', sql('SELECT COUNT(*) FROM tbl_reading WHERE reading_id=' + reset_id), '1')
        post('save_reading', {'reading_id':after_reset_id,'reading_date':'2027-01-01','meter_reading':'20000'})
        post('delete_reading', {'id':reset_id}, False)
        expect('reset boundary survives when new meter surpassed old meter', sql('SELECT COUNT(*) FROM tbl_reading WHERE reading_id=' + reset_id), '1')
        post('save_reading', {'reading_id':after_reset_id,'reading_date':'2027-01-01','meter_reading':'20000','meter_reset':'1'})
        post('delete_reading', {'id':reset_id})
        expect('reset deletion allowed after marking successor reset', sql('SELECT meter_reset FROM tbl_reading WHERE reading_id=' + after_reset_id), '1')
        post('delete_reading', {'id':after_reset_id})
        post('delete_reading', {'id':new_id})
        post('import_readings', upload=b'\xef\xbb\xbfreading_date,meter_reading,meter_reset\n"2026-11-01","18000.500",0\n2026-12-01,18800.750,0\n')
        expect('quoted/BOM CSV imports decimals', sql("SELECT meter_reading FROM tbl_reading WHERE reading_date='2026-12-01'"), '18800.750')
        before_import = sql('SELECT COUNT(*) FROM tbl_reading')
        post('import_readings', success=False, upload=b'reading_date,meter_reading\n2027-01-01,20000\n2027-02-30,21000\n')
        expect('invalid CSV never partially imports', sql('SELECT COUNT(*) FROM tbl_reading'), before_import)
        post('import_readings', success=False, upload=b'reading_date,meter_reading\n2027-01-01,20000\n2026-11-01,18000.5\n')
        expect('existing-date conflict rolls back complete CSV', sql('SELECT COUNT(*) FROM tbl_reading'), before_import)
        post('import_readings', success=False, upload=b'reading_date,meter_reading\n2027-01-01,=1+1\n')
        post('import_readings', success=False, upload=b'reading_date,meter_reading\n' + b' ' * (1024*1024))
        status, headers, body = request('/endpoint/export.php?from=2026-11-01&to=2026-12-01')
        expect('CSV export succeeds', status, 200)
        expect('CSV export content type', headers['Content-Type'], 'text/csv; charset=utf-8')
        expect('CSV download disposition', 'attachment;' in headers['Content-Disposition'])
        expect('CSV export parsed fields and range', list(csv.reader(io.StringIO(body))), [['reading_date','meter_reading','meter_reset'],['2026-11-01','18000.500','0'],['2026-12-01','18800.750','0']])
        expect('CSV reversed range rejected', request('/endpoint/export.php?from=2026-12-01&to=2026-01-01')[0], 400)
        expect('CSV array parameter rejected', request('/endpoint/export.php?from[]=2026-12-01')[0], 400)
        expect('CSV POST rejected', request('/endpoint/export.php', {})[0], 405)
        room_name = 'Research, "Lab" & <b>demo</b>'
        post('add_room', {'name':room_name, 'area':'15.25'})
        room_id = sql('SELECT MAX(room_id) FROM rooms')
        expect('room text persisted without damage', sql('SELECT name FROM rooms WHERE room_id=' + room_id), room_name)
        post('add_room', {'name':room_name, 'area':'20'}, False)
        equipment = {'room_id':room_id,'name':'Test display','watts':'80.125','quantity':'2','hours_per_day':'6.5','days_per_month':'22'}
        post('save_equipment', equipment)
        equipment_id = sql('SELECT MAX(equipment_id) FROM equipment')
        post('delete_room', {'id':room_id}, False)
        post('save_equipment', dict(equipment, equipment_id=equipment_id, hours_per_day='25'), False)
        expect('invalid equipment edit preserves hours', sql('SELECT hours_per_day FROM equipment WHERE equipment_id=' + equipment_id), '6.50')
        post('save_equipment', dict(equipment, room_id='2147483647'), False)
        post('save_equipment', dict(equipment, equipment_id=equipment_id, quantity='3'))
        expect('equipment edit saved', sql('SELECT quantity FROM equipment WHERE equipment_id=' + equipment_id), '3')
        post('delete_equipment', {'id':equipment_id})
        post('delete_room', {'id':room_id})
        action = {'title':'Review monitor sleep','owner':'Test owner','due_date':'2026-10-10','status':'open','estimated_kwh':'12.125','notes':'A "quoted", measurable proposal.'}
        post('save_action', action)
        action_id = sql('SELECT MAX(action_id) FROM actions')
        post('save_action', dict(action, action_id=action_id, status='completed'), False)
        post('save_action', dict(action, action_id=action_id, status='completed', completed_date='2026-10-01'))
        expect('action completion saved', sql('SELECT completed_date FROM actions WHERE action_id=' + action_id), '2026-10-01')
        post('save_action', dict(action, action_id=action_id))
        expect('reopened action clears completion', sql('SELECT completed_date FROM actions WHERE action_id=' + action_id), 'NULL')
        post('save_action', dict(action, action_id=action_id, estimated_kwh='-179.520'))
        expect('negative projected reduction represents increased usage', sql('SELECT estimated_kwh FROM actions WHERE action_id=' + action_id), '-179.520')
        post('save_action', dict(action, action_id=action_id, estimated_kwh='-1000000000'), False)
        expect('out-of-range projected change rejected without mutation', sql('SELECT estimated_kwh FROM actions WHERE action_id=' + action_id), '-179.520')
        post('save_action', dict(action, action_id=action_id, estimated_kwh='999999999'))
        expect('maximum projected reduction accepted', sql('SELECT estimated_kwh FROM actions WHERE action_id=' + action_id), '999999999.000')
        post('delete_action', {'id':action_id})
        settings = {'office_name':'Integration office','tariff':'9.125','monthly_target':'800','monthly_budget':'7000.50','emission_factor':'0.60','emission_source':'Integration test assumption','floor_area':'140.50','working_days':'23'}
        post('save_settings', settings)
        expect('tariff setting persisted', sql("SELECT setting_value FROM office_settings WHERE setting_key='tariff'"), '9.125')
        retained = post('save_settings', dict(settings, office_name='Must not save', tariff='-1',
            password='never-retain-test-value', current_password='never-retain-test-value',
            new_password='never-retain-test-value', confirm_password='never-retain-test-value',
            arbitrary_unknown='never-retain-test-value'), False)
        for key in ['password', 'current_password', 'new_password', 'confirm_password', 'arbitrary_unknown']:
            expect('failed save excludes extra field ' + key, key not in retained['old_keys'])
        for key in ['office_name', 'tariff']:
            expect('failed save retains safe correction field ' + key, key in retained['old_keys'])
        expect('invalid settings save is atomic', sql("SELECT setting_value FROM office_settings WHERE setting_key='office_name'"), 'Integration office')
        expect('settings preserve explicit demo label', sql("SELECT setting_value FROM office_settings WHERE setting_key='data_mode'"), 'demo')
        post('unknown_action', success=False)
        status, headers, _ = request('/endpoint/save.php', {'action':'unknown_action','csrf':session()['csrf'],'return_page':'https://example.com'})
        expect('untrusted return target stays local', headers['Location'], '../index.php?page=overview')
        session()
        run_auth_checks(context)
        run_seed_checks(context)
        log.flush()
        log.seek(0)
        logs = log.read()
        expect('PHP server has no runtime warnings/errors', re.search(r'PHP (Warning|Fatal error|Deprecated|Parse error)', logs), None)
        print(f'OK - {len(checks)} backend integration checks passed on isolated MySQL 3307 and PHP 8086.')
    finally:
        server.terminate()
        try:
            server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait(timeout=5)
        log.close()
