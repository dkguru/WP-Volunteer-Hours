Testing: Unpaid hours CSV export

This document describes simple manual/integration checks to validate the unpaid hours CSV export.

Prerequisites
- A WordPress site with the Volunteer Hours plugin active.
- At least two users with some entries in the vh_entries table; ensure some entries have `paid = 0` and various `work_date` values.
- Administrator account to access Reports and run the export.

Steps
1. Log in as an administrator and go to Volunteer Hours > Reports.
2. Set the From and To date range to cover the dates of the unpaid entries.
3. Click the "Export unpaid hours (CSV)" button.
   - A CSV file download should start (filename: unpaid-hours-<from>-to-<to>.csv).
4. Open the CSV in a text editor or spreadsheet application (Excel/LibreOffice).
5. Verify the CSV format:
   - The header row should be: Date, User, Hours, Projects, Description, Reviewed
   - Each subsequent row is one unpaid entry.
   - The rows should be ordered by User (display name, case-insensitive) ascending, and within the same user by Date ascending. If two entries share the same date, they should be ordered by entry id.
   - The "Hours" column should be formatted the same way as other CSV exports (uses VH_Frontend::fmt_hours).
   - The "Reviewed" column should be "Yes" or "No".
6. Cross-check: pick a user with unpaid entries and verify the rows in the CSV match the entries shown in the All Entries admin screen for that user/date range.

Automated test notes
- This export is implemented as an admin-post endpoint that requires a nonce and manage_options capability. Automated integration tests would need to:
  - Bootstrap WP unit tests or use WP-CLI with a test environment.
  - Create users and entries (wp_insert_user + direct DB inserts or via plugin APIs).
  - Simulate an admin GET to admin-post.php?action=vh_export_unpaid&vh_from=...&vh_to=... with a valid nonce and verify 200 response and CSV content.

If you want, I can prepare a simple PHPUnit integration test using the WordPress testing framework (requires test environment).