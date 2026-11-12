# Kapsabet Express TMS prototype (PHP, MySQL, XAMPP)

## Installation
1. Install XAMPP and start Apache and MySQL in the XAMPP Control Panel.
2. Copy this folder to `C:\xampp\htdocs\kapsabet_tms`.
3. Open http://localhost/phpmyadmin, choose Import, select `sql/database.sql`, and run it (creates database `kapsabet_tms`).
   For an existing installation, import `sql/migrations/002_incident_management.sql` without re-importing `database.sql` (which resets the database).
4. If your MySQL root has a password, set `DB_PASS` in the environment used by Apache, or replace the empty default in `config.php` with that password. You can also set `DB_HOST`, `DB_NAME`, and `DB_USER` there via environment variables. Restart Apache after changing its environment.
5. Open http://localhost/kapsabet_tms/setup.php once, then delete `setup.php`.
6. Open http://localhost/kapsabet_tms/ . Staff login: admin, official, clerk, conductor (password `Pass@1234`).

## Run the tests
Command prompt in the project folder: `C:\xampp\php\php.exe tests\run_tests.php`
(Run setup.php first. Tests run inside a transaction and roll back.)

## Modules
Vehicle registration (vehicles.php), route and fare management (routes.php), queue, trip and boarding (trips.php),
passenger departure search and reservations (index.php, book.php), staff/fleet/route management (management.php),
incident review and trip/financial reports (incidents.php, reports.php), feedback and complaint handling (feedback.php, complaints.php).

Reservations are a prototype only: no M-Pesa request is sent and no payment is collected. Staff can check in a reservation and record a demo fare; unclaimed reservations expire at departure.

### Important gaps
- **No real online payment integration.** Reservations are supported, but the payment/check-in flow is explicitly a no-charge prototype and does not connect to M-Pesa.
- **Reporting is operational rather than analytical:** trip/financial reports and incident reviews are available, but reports do not currently export to CSV or provide advanced trend analysis.
- **Route and stage changes are additive:** existing records are retained to protect trip history; there are no edit/delete controls for routes or stages.
