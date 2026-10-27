# Green Office

A local PHP/MySQL workspace for a college or portfolio demonstration. Tracks cumulative meter readings, estimates electricity costs and grid emissions, models equipment, and records improvement actions.

No build step or package manager needed for the app. Chart.js 4.5.1 is bundled with its license; pages work without CDNs or external fonts.

For the isolated local demo on this Mac, run `bash scripts/start-local.sh`, then open http://127.0.0.1:8085 . It keeps its own database under `.local/mysql` on port 3307, leaves existing MySQL data untouched, and stops its database process when the preview stops. PHP and MySQL binaries must be installed. `.local` is excluded from version control and blocked by the preview router.

## Admin and user panels

Sign in at `/login.php`. Each account opens its assigned panel: `/admin.php` or `/user.php`.

| Capability | Administrator | Office user |
| --- | --- | --- |
| View dashboard, equipment, reports and CSV exports | Yes | Yes |
| Explore savings scenarios | Yes | Yes |
| Create and manage saving actions | All actions | Own actions only |
| Add/edit/delete readings, import CSV | Yes | No |
| Manage rooms, equipment and office settings | Yes | No |
| Create accounts, change roles, reset passwords, deactivate access | Yes | No |
| Change own password and sign out | Yes | Yes |

Permissions are enforced on the server, including direct URLs, form submissions, CSV exports and legacy endpoints. Shared reports show office activity; a user's action list/edit controls include only actions created by that account. Older actions without a creator remain administrator-managed. Freeform owner labels never grant access.

Initial local demo accounts:

| Panel | Email | Initial password |
| --- | --- | --- |
| Admin | `admin@greenoffice.test` | `GreenAdmin!2026` |
| User | `user@greenoffice.test` | `GreenUser!2026` |

These are deliberately public demo credentials, shown on the demo sign-in page. They are created only by explicit `--demo` setup, only for a demo workspace with no accounts. Rerunning setup never resets an existing account/password. Keep this sample workspace on loopback; use a manual workspace with private accounts for real office data.

For a new manual workspace, run `php scripts/setup.php`, then `php scripts/create-admin.php` with the same database environment. The terminal prompts for name, email, password and confirmation, hiding password entry. It refuses to create a bootstrap admin if an active administrator already exists. Subsequent accounts are created in **Admin panel → Account management**. No public registration or email password recovery is provided; an administrator can set a replacement password.

Passwords require at least 12 characters and at most 72 UTF-8 bytes and are stored as hashes. Password changes, account edits, deactivation and role changes revoke existing sessions for the affected account; the current administrator retains their own session after a self-edit. Removing your own admin role/deactivating yourself is blocked. Sign-in failures use a 15-minute limit per email/IP; sessions rotate after sign-in and password changes. Logout and account mutations require POST and CSRF validation.

## Requirements and start

- PHP 8.3+ with `pdo_mysql` and `mbstring` (tested here on PHP 8.5.11).
- MySQL 8+ (tested here on MySQL 26.7.0).
- PHP's built-in web server is for local development only.

From the project directory, with MySQL running:

```sh
php scripts/setup.php --demo
php -S 127.0.0.1:8085 router.php
```

Open http://127.0.0.1:8085 . `--demo` explicitly seeds a sample office only when all application data tables are empty. Omit `--demo` for an empty workspace with a manual-data label. No real sensor connection is implied.

Connection settings are environment variables; existing XAMPP defaults are root with an empty password:

| Variable | Default |
| --- | --- |
| `GREEN_DB_HOST` | `127.0.0.1` |
| `GREEN_DB_PORT` | `3306` |
| `GREEN_DB_NAME` | `green_office_db` |
| `GREEN_DB_USER` | `root` |
| `GREEN_DB_PASSWORD` | empty |

Set the same environment for setup and the web server. For a separate database/port:

```sh
export GREEN_DB_PORT=3307
export GREEN_DB_NAME=green_office_portfolio
php scripts/setup.php --demo
php -S 127.0.0.1:8085 router.php
```

Do not commit real credentials. For public hosting, use private accounts, HTTPS, production server configuration and a restricted database account; review deployment security separately. Local role checks are not deployment certification. Keep the development server bound to `127.0.0.1`.

## Existing project upgrade

Back up the database first. Run `php scripts/setup.php` against the existing configured database. Setup is idempotent: it preserves readings, adds supporting tables, upgrades meter totals to three decimal places, and adds the explicit meter-reset flag. It refuses a decimal conversion that would change existing values.

The account upgrade adds users, sign-in throttling and nullable action ownership without changing readings or claiming ownership of legacy actions. Existing manual installations then use the terminal bootstrap command above. Existing demo installations may run `php scripts/setup.php --demo` once to add demo accounts if no accounts exist.

Legacy duplicate dates remain visible and flagged rather than being deleted. Correct those records in the UI, then rerun setup to add the unique date index. The original exact six sample readings are recognized as demo data. Other existing data stays labeled manual. `--demo` never overwrites a nonempty workspace.

The SQL file now creates tables without `DROP TABLE`. The CLI handles upgrades and database creation. If importing SQL manually, select the database first; use the CLI afterward for existing-schema migration.

## Screens

- **Overview:** recorded kWh, estimated cost/emissions, daily average chart, prorated goals, date filters, reading add/edit/delete/reset, CSV import/export.
- **Rooms & equipment:** room groups and editable power/quantity/schedule estimates. Room deletion is blocked while equipment remains.
- **Savings simulator:** compare current/proposed watts and hours, see monthly kWh/cost/CO₂ change, save scenario assumptions as an action.
- **Alerts & actions:** explainable reading alerts; action owner, due date, completion date, notes, projected savings, and observed before/after daily averages.
- **Reports:** filtered interval table, assumptions, floor-area intensity, action list, CSV export, browser Print / Save PDF.
- **Settings:** office name, tariff, budget, energy target, floor area, working days, and emissions-factor provenance.
- **Account management (admin):** create/edit accounts, roles, active status and replacement passwords; action history survives deactivation.
- **My profile (both roles):** current account details and password change.

## What the figures mean

**Readings:** enter the cumulative total shown on the meter, not a monthly consumption amount. One verified reading per date. Three decimal places supported. Mark the first reading after a real replacement/reset. Consumption across the reset remains unknown.

**Intervals:** energy is the difference between consecutive trustworthy readings. Dates are interval boundaries. Unequal periods compare using kWh/day. Partially selected intervals are excluded because monthly cumulative totals cannot tell how much electricity was used inside an arbitrary date slice. A lower unmarked reading is flagged; it does not become the next trustworthy baseline.

**Goals:** monthly target and budget are prorated by covered calendar days, using actual month lengths. Current settings apply to all displayed history; there is no historical tariff/goal versioning.

**Costs:** kWh × entered ₹/kWh. Taxes, fixed charges, slabs, demand charges, and time-of-use pricing are excluded. This is an estimate, not a utility bill.

**Emissions:** electricity kWh × configured grid factor. Default 0.71 is explicitly a demo assumption. Enter a documented source/year suitable for the reporting grid. This is electricity-related CO₂, not the office's complete greenhouse-gas inventory or certification.

**Equipment/scenarios:** watts × quantity × hours/day × days/month ÷ 1,000. These are assumption-based estimates; never added to measured meter totals. Negative projected reductions mean increased usage. Variable-load equipment needs realistic average power, not an assumed constant nameplate maximum.

**Before/after:** weighted daily consumption from complete intervals within 90 days each side of the action's completion. Insufficient data shows a clear unavailable state. Weather and occupancy can change usage; observed change does not establish causal savings.

**Alerts:** missing data, reading-quality issues, prorated target breaches, and daily-average increases are rules that prompt investigation. No machine learning or equipment-fault diagnosis is claimed.

## CSV

```csv
reading_date,meter_reading,meter_reset
2026-11-01,17750,0
2026-12-01,18420,0
```

Download sample template from Overview. `meter_reset` may be omitted; defaults to `0`. Maximum 1 MB and 2,000 rows. Import validates complete candidate history before any insert. Duplicate or invalid file rejects as a whole. Export has the same three columns and filters by reading date; a filtered export may need a preceding baseline reading to reproduce the original consumption report. Reimporting into the same database is intentionally rejected as duplicate data.

## Validation

```sh
php check.php
```

Backend integration: `python3 tests/integration.py` while the isolated local MySQL instance runs. It includes `tests/auth.py`, recreates only `green_office_backend_test` on port 3307 and starts/stops its own PHP test server on 8086. Never point this test script at a production database. Auth checks cover both roles, forbidden direct submissions, action ownership, login throttling, session revocation, password changes, bootstrap and idempotent migration.

Shared production calculations are tested directly: irregular intervals, leap months, filters, duplicates, resets, legacy corruption, decimal validation, simulator arithmetic, alerts, before/after weighting, and CSV safety. Tests exit nonzero on failure.

Browser QA evidence and report are in `.gstack/qa-reports/`. This is local evidence, not production/deployment verification.

Replay browser checks with `python3 tests/browser.py`, then `python3 tests/browser_roles.py`, after starting the untouched local demo. They require the installed gstack `browse` binary (`GSTACK_BROWSE` may override its path), expect the seven seeded readings/default settings and initial demo credentials, and create/remove QA records. The role script also removes its exact temporary account from the isolated demo database after browser checks. Use a dedicated demo instance; do not run against working office data. Failed runs may leave QA-prefixed records for inspection. Latest check counts and evidence are in the QA reports.

## Code map

| Path | Responsibility |
| --- | --- |
| `index.php` | Server-rendered screens and accessible forms |
| `assets/app.css`, `assets/app.js` | Responsive styling, charts, simulator, confirmations |
| `lib/energy.php` | Shared calculations and reading validation |
| `lib/store.php` | Persistence helpers and trust-boundary validation |
| `lib/bootstrap.php` | Session, CSRF, escaping, flash messages |
| `lib/auth.php` | Account lookup, role guards, ownership, password rules and login throttling |
| `admin.php`, `user.php`, `login.php` | Role-specific entry points and shared sign-in |
| `endpoint/auth.php`, `endpoint/accounts.php` | Login/logout, password changes and admin account management |
| `scripts/create-admin.php` | Terminal-only initial administrator setup |
| `conn/conn.php` | Environment-based PDO connection |
| `endpoint/save.php` | Validated POST mutations and atomic CSV import |
| `endpoint/export.php` | Reading CSV download |
| `scripts/setup.php` | CLI-only safe schema upgrade and opt-in demo seed |
| `router.php` | Local preview; blocks internal files and scripts from HTTP |
| `green_office_db.sql` | Non-destructive fresh schema |
| `check.php` | Repeatable domain self-check |

Hardware ingestion, hourly heatmaps, solar integration, automated control, SSO/email recovery and production deployment remain outside this version.
