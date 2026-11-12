# Kapsabet Express TMS (PHP, MySQL, XAMPP; demo payments only)

## Installation
1. Install XAMPP and start Apache and MySQL in the XAMPP Control Panel.
2. Copy this folder to `C:\xampp\htdocs\kapsabet_tms`.
3. Open http://localhost/phpmyadmin, choose Import, select `sql/database.sql`, and run it (creates database `kapsabet_tms`).
   Do not re-import `database.sql` into an existing installation; it resets the database.
4. If your MySQL root has a password, set `DB_PASS` in the environment used by Apache, or replace the empty default in `config.php` with that password. You can also set `DB_HOST`, `DB_NAME`, and `DB_USER` there via environment variables. Restart Apache after changing its environment.
5. In a command prompt opened in the project folder, run `C:\xampp\php\php.exe seed_demo.php`. This CLI-only script inserts demo accounts, vehicles, and (when the queue is clear) a sample upcoming trip. It does not overwrite existing user or vehicle records.
6. Open http://localhost/kapsabet_tms/ . New demo accounts: admin, official, clerk, conductor, driver, owner (password `Pass@1234`).

## Run the tests
Command prompt in the project folder: `C:\xampp\php\php.exe tests\run_tests.php`
(Run `seed_demo.php` first. Tests run inside a transaction and roll back.)

## Modules
Vehicle registration (vehicles.php), route and fare management (routes.php), queue, trip and boarding (trips.php),
passenger departure search and reservations (index.php, book.php), staff/fleet/route management (management.php),
feedback and complaint handling (feedback.php, complaints.php), role-based staff login, and password changes (account.php).

All fares and payments are demo records. The system does not contact M-Pesa or any payment provider, accept real payment references, move money, or collect cash. Demo receipts are stored in MySQL; passengers can look up and cancel eligible reservations using the receipt reference and phone number. Cancellation and no-show expiry reverse the simulated payment.

The database contains seeded roles, routes, fares, staff accounts, vehicles, and vehicle documents. New demo accounts created by `seed_demo.php` share the password `Pass@1234`; change the passwords from My account before using the system beyond a private demo.
