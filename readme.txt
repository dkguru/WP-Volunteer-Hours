=== Volunteer Hours ===

Contributors: livingislands
Tags: volunteers, hours, time tracking, reports, nonprofit
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.9
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Let (logged-in) volunteers register their hours against organization projects, correct their own entries, and give admins per-user and per-project CSV reports.

== Description ==

Volunteer Hours is a deliberately plain, standalone plugin for organizations that need volunteers to log the hours they work.

**For administrators** (Volunteer Hours menu in wp-admin):

* Maintain the project list separately — just project names; deactivate a project to hide it from the form without losing history
* See, filter, correct, and export every entry
* Two checkboxes per entry: **Reviewed/Approved** and **Paid**. Hours can only be marked paid after they have been reviewed;
  un-reviewing clears the paid flag. Volunteers see the status of each entry, and paid entries are locked against volunteer changes
* Run reports for any date range: hours per user and hours per project, each with one-click CSV export (opens directly in Excel)

**For volunteers** (via the `[volunteer_hours]` shortcode on any page):

* Must be logged in to the WordPress site to register hours
* Enter the date worked, number of hours, a description, and check one or more of the organization's current projects
* See their own registered hours per month with a total, and edit or delete entries to fix mistakes
* Download their monthly sheet as CSV, or print it (the browser print dialog saves it as PDF)

No page builders, no styling frameworks, no external services.
Three custom database tables, standard WordPress security (nonces, capability checks, prepared statements),
and CSV output with Excel formula-injection protection.

== Installation ==

1. In wp-admin, go to Plugins > Add New > Upload Plugin, choose volunteer-hours.zip, install, and activate.
2. Go to Volunteer Hours > Projects and add your current projects.
3. Create a page for volunteers and put the shortcode `[volunteer_hours]` in it.
4. Ensure volunteers have accounts on the site (any role — Subscriber is enough).

== Frequently Asked Questions ==

= Can an entry belong to several projects? =
Yes. The form is a checkbox list, so a piece of work that touches two or three projects is checked against all of them.
In the per-project report those hours count fully toward each checked project; the per-user report counts each entry once.

= How do volunteers get a PDF? =
The "Print / Save as PDF" button opens a clean sheet and triggers the browser print dialog,
where every modern browser offers Save as PDF. CSV export is always available.

= What happens if I delete a project? =
Projects with registered hours cannot be deleted, only deactivated, so history is never lost.

== Screenshots ==

1. The volunteer form: date, hours, multi-select projects, description.
2. "My hours": monthly list with totals, edit/delete, CSV and print buttons.
3. Admin reports: hours per user and hours per project with CSV export.

== Changelog ==

= 1.1.9 =
* Added: unpaid CSV now includes Entry ID and User Email columns.
* Tests: add PHPUnit test scaffold and an unpaid-export test (requires WordPress test environment).

= 1.1.8 =
* Changed: unpaid hours CSV format changed to a flat, one-row-per-entry layout with a User column. Rows are grouped implicitly by sorting (user name asc) and then by date asc.

= 1.1.7 =
* Fixed: redirects built from the current URL could duplicate the site path when WordPress is installed in a subdirectory (e.g. example.com/vh/). Post-submit redirects now build the URL from HTTP_HOST + REQUEST_URI to avoid generating /vh/vh/?vh_msg=... URLs.
* Added: an "Export unpaid hours (CSV)" button on the Reports screen. The export now produces a flat one-row-per-entry CSV with a User column, sorted by user and then by date (ascending).
* Refactor: export URL building consolidated into a helper to reduce duplication.

= 1.1.6 =
* Added: a dismissible message on the Volunteer Hours admin screens inviting support for Living Islands, linking to https://donorbox.org/linp
  Closing it hides the message for three months, per administrator. The URL can be changed with the 'vh_donation_url' filter.

= 1.1.4 =
* Fixed: on sites with a timezone behind UTC (e.g. US timezones), the month-end date was computed in the previous month,
  producing an impossible date range. All Entries, My Hours, and CSV exports showed no records even though entries were saved correctly.

= 1.1.3 =
* Fixed: a failed database insert was reported as "Hours registered". Database errors during save are now shown to the user with the exact error text.
* Added a Plugin status panel on the Reports screen: table existence and row counts, schema version, and a safe Repair/rebuild button.
* Missing tables are now recreated automatically on the next page load, even if the version option is current.

= 1.1.2 =
* Fixed: the All Entries screen (and My Hours) showed no records on MariaDB servers with the default ONLY_FULL_GROUP_BY sql_mode. The entry query no longer uses GROUP BY.
* Database errors on the All Entries screen are now shown as an admin notice instead of failing silently.

= 1.1.1 =
* Fixed: registering hours redirected to the homepage instead of back to the form page, making it appear the entry was not saved and hiding validation messages. Submissions now return to the same page with a confirmation or error message.

= 1.1.0 =
* Added Reviewed/Approved and Paid checkboxes per entry on the All Entries screen (paid requires review first).
* Status shown to volunteers, included in printable sheet and all CSV exports, with a status filter for admins.
* Volunteer edits send an entry back for review; paid entries are locked for volunteers.

= 1.0.0 =
* Initial release.
