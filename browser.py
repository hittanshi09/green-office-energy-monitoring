#!/usr/bin/env python3
"""Browser regression via installed gstack. Uses only the isolated demo on localhost:8085.
Creates QA-prefixed records, exercises UI forms, then removes those records.
"""
import json, os, pathlib, subprocess, tempfile
ROOT = pathlib.Path(__file__).resolve().parents[1]
B = os.environ.get('GSTACK_BROWSE', str(pathlib.Path.home()/'.claude/skills/gstack/browse/dist/browse'))
URL = 'http://127.0.0.1:8085/'
REPORT = ROOT/'.gstack/qa-reports'
REPORT.mkdir(parents=True, exist_ok=True)
checks = []

def run(*args):
    result = subprocess.run([B, *args], cwd=ROOT, text=True, capture_output=True, timeout=35)
    if result.returncode:
        raise AssertionError(f'Browser command {args[0]} failed: {result.stderr or result.stdout}')
    return result.stdout

def chain(steps):
    result = subprocess.run([B, 'chain'], input=json.dumps(steps), cwd=ROOT, text=True, capture_output=True, timeout=60)
    if result.returncode or '"error":' in result.stdout:
        raise AssertionError(f'Browser flow failed: {result.stderr or result.stdout}')
    return result.stdout

def check(label, expression):
    answer = run('js', f'Boolean({expression})')
    if answer.strip() != 'true':
        raise AssertionError(f'{label}: {answer}')
    checks.append(label)
    print('PASS', label, flush=True)

def text_has(text):
    return f'document.body.innerText.includes({json.dumps(text)})'

def goto(page):
    run('goto', URL+'?page='+page)

def click(selector):
    run('click', selector)

def fields(values):
    chain([['fill', selector, str(value)] for selector, value in values.items()])

def delete(selector):
    run('dialog-accept')
    click(selector)

def capture(name):
    run('screenshot', str(REPORT/'screenshots'/name))

run('console', '--clear')
run('viewport','1440x1000')
run('goto', URL)
if run('js', "Boolean(document.querySelector('.logout-form button'))").strip() == 'true':
    click('.logout-form button')
fields({'#login-email':'admin@greenoffice.test','#login-password':'GreenAdmin!2026'})
click('#login-form button[type=submit]')
check('admin login opens admin panel', "location.pathname === '/admin.php'")
goto('overview')
check('demo dataset visibly labeled', text_has('Demo workspace'))
check('baseline total 5050 kWh', text_has('5,050.0'))
check('chart initialized from interval data', "typeof Chart !== 'undefined' && Chart.getChart('usage-chart').data.datasets[0].data.length === 6")
fields({'#reading-date':'2026-10-08','#meter-reading':'17200.500'})
click('#reading-form button[type=submit]')
check('decimal reading saved through UI', text_has('Meter reading added.')+' && '+text_has('17,200.500'))
click('tr:has-text("08 Oct 2026") a')
fields({'#meter-reading':'17201.125'})
click('#reading-form button[type=submit]')
check('reading edit preserved decimals', text_has('Meter reading updated.')+' && '+text_has('17,201.125'))
fields({'#reading-date':'2026-10-08','#meter-reading':'17201.125'})
click('#reading-form button[type=submit]')
check('duplicate reading rejected visibly', text_has('Duplicate reading date'))
check('failed reading preserved for correction', "document.querySelector('#meter-reading').value === '17201.125'")
run('dialog-dismiss')
click('tr:has-text("08 Oct 2026") button')
check('delete cancellation leaves reading', text_has('17,201.125'))
delete('tr:has-text("08 Oct 2026") button')
check('confirmed deletion restores baseline', text_has('Meter reading deleted.')+' && '+text_has('5,050.0'))
fields({'#overview-from':'2026-05-01','#overview-to':'2026-07-01'})
click('.period-filter button[type=submit]')
check('filter selects only complete intervals', text_has('1,910.0'))
fields({'#overview-from':'2026-07-01','#overview-to':'2026-05-01'})
click('.period-filter button[type=submit]')
check('reversed dates show error instead of misleading report', text_has('Choose valid dates'))
goto('overview')
with tempfile.TemporaryDirectory(prefix='green-office-browser-') as td:
    valid = pathlib.Path(td)/'valid.csv'
    valid.write_text('reading_date,meter_reading,meter_reset\n2026-10-15,17400.125,0\n2026-10-20,17600.250,0\n')
    invalid = pathlib.Path(td)/'invalid.csv'
    invalid.write_text('reading_date,meter_reading,meter_reset\n2026-10-25,17900,0\n2026-10-20,17500,0\n')
    run('upload','#readings-file',str(valid)); click('#import-form button[type=submit]')
    check('CSV upload creates both readings',text_has('17,400.125')+' && '+text_has('17,600.250'))
    run('upload','#readings-file',str(invalid)); click('#import-form button[type=submit]')
    check('bad CSV fully rolled back',text_has('Duplicate reading date')+' && !'+text_has('17,900.000'))
    delete('tr:has-text("20 Oct 2026") button'); delete('tr:has-text("15 Oct 2026") button')
check('CSV test records cleaned', text_has('5,050.0'))

goto('equipment')
fields({'#room-name':'QA Focus room','#room-area':'18'})
click('#room-form button[type=submit]')
check('room saved',text_has('QA Focus room'))
fields({'#equipment-name':'QA Desk lights','#equipment-watts':'200','#equipment-quantity':'2','#equipment-hours':'4','#equipment-days':'22'})
run('select','#equipment-room','QA Focus room')
click('#equipment-form button[type=submit]')
check('equipment estimate calculated',text_has('QA Desk lights')+' && '+text_has('35.2'))
click('tr:has-text("QA Desk lights") a')
fields({'#equipment-watts':'150'})
click('#equipment-form button[type=submit]')
check('equipment edit recalculates estimate',text_has('26.4'))
delete('tr:has-text("QA Desk lights") button')
check('equipment removed', '!'+text_has('QA Desk lights'))
delete('.room-card:has-text("QA Focus room") button')
check('empty room removed','!'+text_has('QA Focus room'))

goto('simulator')
check('LED scenario correct', "document.querySelector('#saved-kwh').textContent === '179.52'")
fields({'#scenario-new-hours':'25'})
check('invalid scenario cannot be saved',"document.querySelector('#save-scenario').disabled")
fields({'#scenario-new-hours':'8','#scenario-title':'QA LED scenario'})
click('#save-scenario')
check('scenario saved as action with estimate',text_has('QA LED scenario')+' && '+text_has('179.5'))
click('.action-row:has-text("QA LED scenario") a')
run('select','#action-status','completed')
fields({'#completed-date':'2026-10-01','#action-owner':'QA tester'})
click('#action-form button[type=submit]')
check('action completion persists',text_has('QA tester')+' && '+text_has('Completed')+' && '+text_has('Observed office daily average'))
delete('.action-row:has-text("QA LED scenario") button')
check('action deletion confirmed','!'+text_has('QA LED scenario'))
goto('simulator')
fields({'#scenario-new-watts':'90','#scenario-title':'QA Increased load'})
check('negative reduction displayed',"document.querySelector('#saved-kwh').textContent === '-105.60'")
click('#save-scenario')
check('signed scenario saves consistently',text_has('QA Increased load')+' && '+text_has('105.6 kWh/month estimated increase'))
delete('.action-row:has-text("QA Increased load") button')

goto('settings')
fields({'#office-name':'QA Office','#tariff':'10'})
click('form:has(input[name=action][value=save_settings]) button[type=submit]')
check('settings persisted',text_has('Office settings saved.'))
goto('overview')
check('cost changes with tariff',text_has('50,500'))
goto('settings')
fields({'#office-name':'Green Office','#tariff':'8'})
click('form:has(input[name=action][value=save_settings]) button[type=submit]')
check('settings restored',"document.querySelector('#tariff').value === '8'")
goto('reports')
check('report includes assumptions and area intensity',text_has('Factor source / year')+' && '+text_has('not annual EUI'))
run('download',URL+'endpoint/export.php',str(REPORT/'exported-readings.csv'))
exported=(REPORT/'exported-readings.csv').read_text()
assert 'reading_date,meter_reading,meter_reset' in exported and len(exported.strip().splitlines()) == 8
checks.append('browser CSV download contains original seven rows')
run('pdf',str(REPORT/'office-energy-report.pdf'),'--format','a4','--print-background')
checks.append('browser print PDF generated')

for page in ['overview','equipment','simulator','actions','reports','settings']:
    goto(page)
    check(page+' has no PHP errors',"!document.body.innerText.match(/Fatal error|Warning:|Deprecated:/)")
    check(page+' desktop no page overflow',"document.documentElement.scrollWidth <= innerWidth + 1")
    capture(page+'-desktop.png')
    run('viewport','375x812')
    check(page+' mobile no page overflow',"document.documentElement.scrollWidth <= innerWidth + 1")
    capture(page+'-mobile.png')
    run('viewport','1440x1000')
    assert '(no console errors)' in run('console','--errors'), 'Browser console errors'
checks.append('zero browser console errors across all flows')
goto('overview')
check('final baseline restored after browser CRUD',text_has('5,050.0'))
(REPORT/'browser-results.json').write_text(json.dumps({'passed':len(checks),'checks':checks},indent=2)+'\n')
print(f'PASS: {len(checks)} browser assertions / artifacts',flush=True)
