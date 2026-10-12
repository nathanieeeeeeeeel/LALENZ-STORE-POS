# LALENZ-SARISARI-POS

**LALENZ-SARISARI-POS** is the core repository for the Lalenz Foodies Point-of-Sale (POS) and Order Management System (OMS). This documentation serves as the central operational and architectural reference for deployment, database persistence, and release continuity.

---

## Technical Overview

- **System Version:** v1.0.2
- **Build Reference:** 10122026
- **Release Tag:** v1.0.2-10122026
- **Architecture:** Client-side interface with centralized backend data persistence & Order Management System (OMS) integration
- **Timezone Standard:** Database datetime records strictly adhere to UTC architecture

---

## Core System Functionality

The v1.0.2 production application maintains the following stable interface and state behaviors:

- **Order Management System (OMS):** Integrated order processing, fulfillment tracking, and status synchronization.
- **Theme Engine:** Theme initialization, validation, light/dark switching, and safe localStorage state handling.
- **Shortcut & Cooldown Controls:** 500ms theme toggle cooldown with Shift + D keyboard shortcut support.
- **UI Stability & Tooltips:** Navigation dropdown stability, defensive Tippy.js initialization, duplicate tooltip prevention, and modal keyboard (Escape) handling.
- **Financial & Sales Synchronizations:** Financial Dashboard relative fetch paths, Sales Report theme synchronization, and general UI refinements.

---

## Directory & File Structure

```text
LALENZ-SARISARI-POS/
├── admin/
│   ├── users/
│   │   ├── _bootstrap.php    # Initializes shared dependencies, database connection, and user management setup
│   │   ├── _footer.php       # Shared footer layout for user management pages
│   │   ├── _header.php       # Shared header layout and navigation for user management pages
│   │   ├── create.php        # Creates new POS system user accounts
│   │   ├── delete.php        # Handles deletion of existing user accounts
│   │   ├── index.php         # Displays and manages the list of user accounts
│   │   ├── modify.php        # Updates existing user account information
│   │   ├── permissions.php   # Manages user permissions and access privileges
│   │   └── roles.php         # Manages user roles and role assignments
│   └── action-logs.php       # Displays administrative activity and action logs
├── api/
│   └── ...
├── assets/
│   └── ...
├── CHANGELOGS/
│   └── ...
├── config/
│   └── ...
├── includes/
│   └── ...
│   
├── admin.php              # Administrative dashboard and system administration functions
├── catgories.php          # Displays and manages product categories
├── category_add.php       # Adds new product categories
├── category_edit.php      # Edits existing product category information
├── customers.php          # Manages customer records and customer information
├── dashboard.php          # Displays POS dashboard metrics and business summaries
├── expenses.php            # Records and manages store expenses
├── index.php               # Main application entry point
├── item-summary.php        # Displays item-level sales summaries and product performance
├── login.php               # Handles user authentication and login
├── pos.php                 # Provides the point-of-sale interface for processing transactions
├── products.php            # Manages product records, prices, and inventory information
├── reports.php             # Generates sales, financial, and operational reports
├── sales.php               # Displays and manages sales transaction records
├── settings.php            # Manages application settings and system preferences
│
├── .gitignore              # Excludes specified environments, build artifacts, and temporary files from Git
└── README.md               # Main project documentation, setup instructions, and system overview
```

---

## Database Architecture & Continuity Standards

### Datetime Handling
All production operational periods, sales timestamps, order lifecycles, and audit records are generated and stored in Coordinated Universal Time (UTC). Conversion to local timezone occurs at the presentation layer only.

### EOD Snapshot Protocol
Daily End-of-Day (EOD) snapshots are executed to ensure operational continuity and complete database state preservation. 

- **Snapshot Numbering:** Incremental numbering corresponding to sequential backup runs.
- **Coverage Alignment:** Operational activity across active dates (including multi-day maintenance runs) is logged explicitly in backup manifests.
- **Preservation Verification:** Core historical records (e.g., authoritative expense logs and order history) are systematically verified during every EOD execution.

---

## Operational Backup Workflow

To create and log a standard EOD database snapshot:

1. **Verify State:** Confirm no pending migrations or uncommitted application build changes exist.
2. **Export Database:** Generate the full SQL snapshot and place it in /database/snapshots/.
3. **Log Manifest:** Update the backup ledger document with current build tags, backup scope, and dates.
4. **Commit Record:** Format the git commit message using standard backup message conventions:

backup: add EOD database snapshot #<NUMBER> for <START_DATE> to <END_DATE>
- Added EOD database and application backup for <START_DATE> to <END_DATE>
- Retained production application version v1.0.2-10122026
- Preserved current production database state through <END_DATE>
- Database schema remains unchanged (No migration required)

---

## Development & Local Environment

### Prerequisites
- HTTP web server (Apache / Nginx / Node static server)
- MySQL / MariaDB SQL database server

### Local Setup
1. Clone the repository:
   git clone https://github.com/your-username/LALENZ-SARISARI-POS.git
   cd LALENZ-SARISARI-POS
2. Import the latest database snapshot from /database/snapshots/ into your local SQL engine.
3. Configure your local web server root to serve from the /src directory.

---

## License & Operational Policy

Internal proprietary software created for Lalenz Foodies. Authorized production and backup operations only.
