# Kapsabet Express TMS (PHP, MySQL, XAMPP; simulated payments only)

## Installation
1. Install XAMPP and start Apache and MySQL in the XAMPP Control Panel.
2. Copy this folder to `C:\xampp\htdocs\kapsabet_tms`.
3. Open http://localhost/phpmyadmin, choose Import, select `sql/database.sql`, and run it (creates database `kapsabet_tms`).
   Do not re-import `database.sql` into an existing installation; it resets the database.
4. If your MySQL root has a password, set `DB_PASS` in the environment used by Apache, or replace the empty default in `config.php` with that password. You can also set `DB_HOST`, `DB_NAME`, and `DB_USER` there via environment variables. Restart Apache after changing its environment.
5. In a command prompt opened in the project folder, run `C:\xampp\php\php.exe seed_data.php`. This CLI-only script inserts sample accounts, vehicles, and (when the queue is clear) an upcoming trip. It does not overwrite existing user or vehicle records.
6. Open http://localhost/kapsabet_tms/ . New sample accounts: admin, official, clerk, conductor, driver, owner (password `Pass@1234`).

For an existing installation, import `sql/migrations/003_daily_timetable.sql` once using phpMyAdmin before opening the application. Do not re-import `sql/database.sql` into an existing database.


## Modules
Vehicle registration (vehicles.php), route and fare management (routes.php), queue, trip and boarding (trips.php),
passenger departure search and reservations (index.php, book.php), staff/fleet/route management (management.php),
feedback and complaint handling (feedback.php, complaints.php), role-based staff login, and password changes (account.php).

The daily timetable runs four trips every day: 06:00 Kapsabet to Eldoret, 10:00 Eldoret to Kapsabet, 14:00 Kapsabet to Eldoret, and 18:00 Eldoret to Kapsabet. The first active, compliant vehicle and active driver/conductor are assigned when the timetable is initialized. Upcoming trip records are generated for 30 days; staff start each scheduled trip from the Trips page before boarding.

All fares and payments are simulated records. The system does not contact M-Pesa or any payment provider, accept real payment references, move money, or collect cash. Simulated receipts are stored in MySQL; passengers can look up and cancel eligible reservations using the receipt reference and phone number. Cancellation and no-show expiry reverse the simulated payment.
