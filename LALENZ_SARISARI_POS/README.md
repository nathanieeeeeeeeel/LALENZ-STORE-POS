# LALENZ Sari-Sari POS

A web-based point-of-sale and store-management application for Lalen'z Store. It brings checkout, product and inventory management, sales history, expenses, reporting, and staff permissions together in one application.

**Current application version:** `v1.0.2-10122026`  
**External documentation repository:** [LALENS-SARISARI-POS](https://github.com/nathanieeeeeeeeel/LALENS-SARISARI-POS)

## How the Project Began

LALENZ Sari-Sari POS was created to support the day-to-day work of a small retail store with a single, practical system. Its central purpose is to make recording a sale and keeping track of products easier than managing those tasks separately.

The application has grown from that checkout-and-inventory focus into a broader store operations tool. The documented releases added user roles and permissions, product auditing, action logs, expense tracking, sales reports, customer details on transactions, configurable receipt information, and chart ranges. The dated release notes are available in [`CHANGELOGS/`](./CHANGELOGS/).

## What It Does

### Point of Sale

- Build a cart from available products and supported load/e-service products.
- Calculate the transaction total, cash tendered, and change due.
- Confirm checkout before recording the sale.
- Optionally record a customer's name, contact information, address, and transaction note.
- Clear checkout inputs after a successful sale and automatically dismiss the confirmation message.

### Products and Inventory

- Maintain product and category records, prices, stock, and reorder levels.
- Use category-based SKUs and protect SKU uniqueness.
- Track product creation and modification metadata, including the responsible user.
- Show stock status based on each product's reorder threshold.
- Treat load and e-service products differently from physical inventory during checkout.

### Sales and Receipts

- Browse sales history and search transaction records.
- Review transaction details, including customer details, notes, cashier, and purchased items.
- Edit sale items and saved customer information where the user's permissions allow it.
- Print a receipt with customer name, TIN, address, and VAT registration TIN fields.
- Configure the receipt's store address and footer message in General Settings.
- Choose standard or 58 mm receipt width. The receipt window closes after the print dialog is dismissed.

### Expenses and Reports

- Record and manage store expenses.
- Review expense details and action history.
- View sales and expense summaries, revenue, transactions, net profit/loss, and average sale.
- Switch between Monthly and Yearly report views.
- In Monthly view, independently chart the last 24 hours or 7, 15, 30, or 60 days.
- Export reports to supported document, spreadsheet, PDF, and image formats.

### Accounts, Permissions, and Settings

- Authenticate staff accounts and protect application pages with permission checks.
- Use predefined roles, custom roles, and per-user permission overrides.
- Review logged actions, including successful operations and failures.
- Configure the application name, store logo, receipt address, and receipt footer.
- Change account passwords and create or restore database backups from Settings when permitted.

## Technology

- PHP
- MySQL or MariaDB
- PDO for database access
- HTML, CSS, and JavaScript
- Tailwind CSS and Chart.js for parts of the interface and reports

The application is currently configured for a local XAMPP installation and uses the database name `lalenz_sarisari_pos`.

## Getting Started (Local XAMPP)

### Requirements

- Windows with XAMPP
- Apache and MySQL enabled in the XAMPP Control Panel
- A PHP version with the PDO MySQL driver and the extensions used by the application enabled
- A provisioned MySQL/MariaDB database and application user records

### Setup

1. Place the project folder in XAMPP's `htdocs` directory. The current configuration expects the application at:

   ```text
   C:\xampp\htdocs\LALENZ_SARISARI_POS
   ```

2. Create the `lalenz_sarisari_pos` database and provision the schema and initial user data required by the application. This repository does not currently include a database schema dump or an automated installer.

3. Review [`config/database.php`](./config/database.php) and set the database host, name, username, and password for your environment. The checked-in values use the standard local XAMPP defaults; do not use default credentials for a network-accessible or production deployment.

4. Start Apache and MySQL, then open the application through the local web server:

   ```text
   http://localhost/LALENZ_SARISARI_POS/
   ```

5. Sign in with an application user that has been provisioned in the database. Grant only the permissions each staff member needs.

> **Important:** This project is designed for local use as configured. Before exposing it outside a trusted local environment, configure secure database credentials, HTTPS, backups, and an appropriate production server setup. Also review the application paths if installing it under a different folder name or URL.

## Project Layout

```text
.
├── admin/                  # Administration and user-management pages
├── api/                    # Sale details, receipts, and product operations
├── assets/                 # Images and other static assets
├── config/                 # Database, authentication, permission, and app helpers
├── includes/               # Shared layout components
├── CHANGELOGS/             # Dated release notes
├── pos.php                 # Point-of-sale checkout
├── products.php            # Product management
├── categories.php          # Category management
├── sales.php               # Sales history and transaction details
├── expenses.php            # Expense tracking
├── reports.php             # Sales and expense analytics
└── settings.php            # Application settings and backup tools
```

## Release Notes

The latest documented application release is **v1.0.2-10122026**. Its updates include checkout cleanup, richer customer and receipt details, configurable receipt information, and independent date ranges for Monthly report charts.

- [v1.0.2 — 10-12-2026](./CHANGELOGS/v1.0.2/10-12-2026/readme.txt)
- [v1.0.1 — 10-11-2026](./CHANGELOGS/v1.0.1/10-11-2026/readme.txt)
- [v1.0.1 — 10-10-2026](./CHANGELOGS/v1.0.1/10-10-2026/readme.txt)
- [v1.0.0 — 10-07-2026](./CHANGELOGS/v1.0.0/10-07-2026/readme.txt)

## Documentation

The project's external documentation repository is [github.com/nathanieeeeeeeeel/LALENS-SARISARI-POS](https://github.com/nathanieeeeeeeeel/LALENS-SARISARI-POS). It is also linked from the application's Settings page.
