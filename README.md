# LedgerFlow AIS

A web-based Accounting Information System (AIS) built with PHP and MySQL.
It records journal entries, enforces double-entry rules, and produces
vouchers, ledgers, and a live accounting overview.

## Features

- **Dashboard**: totals for assets, liabilities, equity, income, expenses and
  net income, with a Balanced / Not Balanced check on the accounting equation,
  a fiscal-year selector, and a list of the latest posted transactions.
- **Incoming Transaction**: record a journal entry with payee, description and
  multiple debit/credit lines. Entries whose debits and credits are not equal
  cannot be saved. Each saved entry gets a series number, then a
  **Generate Journal Entry Voucher** button.
- **Journal Entry Report**: list of posted entries and a printable voucher
  (use **Generate PDF**, then choose *Save as PDF*).
- **Ledger Accounts**: general ledger per account with running balance,
  **Print / Save as PDF** and **Download CSV**.
- **Chart of Accounts**: add, edit, activate and deactivate accounts.
- **User Management** (admin only): create users and activate/deactivate them.

## Roles

| Role | Access |
|---|---|
| Admin | Everything, including User Management and editing the Chart of Accounts |
| Finance Officer | Transactions, reports, ledger, and view-only Chart of Accounts |

## Key rules

- **Series number format:** `MM-SEQ-YYYY`, for example `11-001-2026`.
  The sequence restarts every month.
- **Fiscal year:** calendar year (January 1 to December 31).
  Assets, liabilities and equity are cumulative through the end of the selected
  year. Income and expenses cover that year only.
- Posted entries cannot be edited or deleted.
- Accounts are never deleted. An account can only be deactivated when its
  posted balance is zero, and its type and normal balance are locked once it
  has been used.
- Amounts are handled in whole cents to avoid rounding errors.

## Requirements

- [XAMPP](https://www.apachefriends.org/) with PHP 8+ and MariaDB/MySQL
- A modern web browser

## Setup

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Clone or unzip this project to `C:\xampp\htdocs\ledgerflow`.
3. Open `http://localhost/phpmyadmin`, click **New**, and create a database
   named `ledgerflow_local` with collation `utf8mb4_unicode_ci`.
4. Open that database, choose **Import**, and select `database/ledgerflow_local.sql`.
5. Copy `config/database.example.php` to `config/database.php` and fill in your
   own MySQL settings (XAMPP default: user `root`, empty password).
   `config/database.php` is ignored by Git and must never be committed.
6. Open `http://localhost/ledgerflow/` in your browser and log in.

If the database has no users yet, open `setup_admin.php` once to create the
first admin account, then **delete or restrict that file** before hosting the
system anywhere public.

## Project structure

```
ledgerflow/
  auth/          login.php, logout.php
  assets/css/    app.css (shared stylesheet)
  config/        database.php (local, ignored), database.example.php
  database/      ledgerflow_local.sql (structure and data)
  includes/      auth_check.php, sidebar.php (shared layout pieces)
  pages/         dashboard, journal_entries, journal_vouchers,
                 general_ledger, chart_of_accounts, user_management
```

Every page loads `assets/css/app.css` and includes `includes/sidebar.php`
after setting `$activePage`, so the menu and styling stay consistent.

## Security notes

- Passwords are stored as hashes (`password_hash` / `password_verify`).
- Forms are protected with CSRF tokens, and all database queries use prepared
  statements.
- Output is escaped with `htmlspecialchars` to prevent XSS.
- The session ID is regenerated at login.

## Working as a team

```bash
git pull                       # before you start
git add .
git commit -m "Describe the change"
git push                       # when you are done
```

Work on different pages to avoid merge conflicts. For larger features, use a
branch: `git checkout -b feature-name`.

After changing data or the database structure, export a fresh copy to
`database/ledgerflow_local.sql` (phpMyAdmin > Export > Custom, with
*Add DROP TABLE* ticked) and commit it.

## Troubleshooting

| Problem | Likely cause |
|---|---|
| "Unknown database" | The `ledgerflow_local` database was not created |
| "Database connection failed" | MySQL is not running, or `config/database.php` is wrong |
| Pages look unstyled | `assets/css/app.css` is missing |
| Peso signs show as `?` | The database was not created with `utf8mb4` |
| Sidebar include error | `includes/sidebar.php` is missing |

## Team

Add your names and roles here.
