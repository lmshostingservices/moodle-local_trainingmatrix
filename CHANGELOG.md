# Changelog

All notable changes to this plugin are listed here.

## [1.1.3] - 2026-11-13

### Changed
- The database tables now use the plugin's own name as their prefix (for example local_tm_staff becomes local_trainingmatrix_staff), as Moodle requires. The upgrade renames them in place and keeps every record.

### Fixed
- The code passes Moodle's full code, PHPDoc and CSS checks on Moodle 4.4 to 5.3 and main.
- Training cards adapt to narrow spaces with a small script instead of CSS container queries. They look the same at every width.
- Card action links are marked as buttons, so themes keep their white text without forcing styles.
- Tests that need Client Portal are skipped when it is not installed.
- The Client label beside the client picker could not be read with the Premium SaaS theme; it now takes the header's text colour.

## [1.1.2] - 2026-11-13

### Added
- Works with Client Portal 2.0.1 and later: each LMS Labs AI call made in a client's course, or by a client's people, is reported to the portal with the credits it used, so the site owner can see and invoice each client's usage. The service's own figure is used when its reply gives one; otherwise the plugin's published price. Calls that fail and polled jobs are never counted twice. Nothing changes on sites without Client Portal.

## [1.1.1] - 2026-11-12

### Fixed
- The compliance ring on a staff member's page showed as a solid black disc; it now draws the ring with the percentage in the middle.

## [1.1.0] - 2026-11-12

Client and line-manager scoping, staff CSV import, and automatic enrolment when a position changes. Tested on Moodle 4.5 (PostgreSQL 16, PHP 8.3), upgrading from 1.0.22.

### Added
- **Clients.** With the Client portal installed, departments, positions and competencies can be shared or belong to one client, and each staff record can be tied to a client. Records only use shared items or their own client's. Existing records become shared / not tied to a client, which is how they worked before.
- **Client selector** in the header for administrators. Lists, statistics, reports and exports follow it; it is remembered per user and shared with Training Pathways.
- **Line managers.** New capability `local/trainingmatrix:viewteam`, given to every authenticated user. People see the Staff and Reports tabs, statistics, reminders and exports for the staff whose manager they are and for everyone in the departments (and sub-departments) they manage. People who manage nobody see nothing extra. The Staff Training Dashboard block works the same way.
- **Import tab.** Upload a CSV of staff (username, email or ID number; position; employee ID, department, manager, dates, status, client). Every row is checked and previewed before anything changes; good rows are applied one at a time and enrol the person in their position's courses. Optionally creates missing positions and departments. New capability `local/trainingmatrix:import`. Template CSV included.
- `scope` service class (`\local_trainingmatrix\local\scope`) so other plugins, such as the Client portal, can read one client's or one manager's staff with the same rules.

### Changed
- Adding a competency to a position, or linking a course to a competency, now enrols everyone already in that position in the course. People who cannot be enrolled (for example a suspended enrolment) are listed in a warning; the others are still enrolled.
- AI-generated competencies belong to the position's client and only reuse shared competencies or that client's own.
- Web services `get_compliance_stats` and `export_report` take an optional `clientid` and return a manager's own team for line managers. `get_staff_completions` allows a manager to read their own team and now fills in the department name.
- Row action menus (View, Edit, Delete) now open as a menu; they were always expanded because the styles were missing.

### Security
- Sending a reminder now checks that the person is inside the sender's scope.

## [1.0.22] - 2026-11-02

Full feature test on a fresh Moodle 5.3 site (CRUD, enrolment, tasks, notifications, reports, exports, AI generation, blocks).

- Competencies that never expire (e.g. induction) could not be entered: the form only allowed 1–120 months. 0 is now allowed and shown as "Does not expire".
- AI-generated competencies were matched to existing ones case-sensitively, so "manual handling" was created next to "Manual Handling". Matching by code or name now ignores case.

## [1.0.21] - 2026-11-02

Tested on Moodle 4.5, 5.0, 5.1, 5.2 and 5.3 (PostgreSQL 16/17, PHP 8.3).

### Fixed
- AI competency generation (positions tab and web service) failed with "Class curl not found"; credentials, credit checks and generation now share one client that also honours local_aiconfig in the web service.
- Stored XSS: AI-returned competency names, codes, categories and descriptions are now cleaned before saving, and names, e-mails, codes, categories, departments and positions are escaped wherever they are printed (tables, select options, textareas).
- Saving or deleting departments, positions, competencies and staff redirected after the page header was printed (nested page, lost notification). Actions now run before any output.
- "Export CSV" linked to a missing export.php (404). Added export.php (CSV/Excel) backed by the export_report service.
- "Send reminder" buttons pointed at "#". They now e-mail the learner and log the reminder.
- A competency linked to more than one course reported whichever tracking row the database returned last; the best row now decides.
- Re-linking a competency to another course deleted and re-created the link and left stale tracking rows; the link is now updated in place and staff in matching positions get a tracking row for the new course.
- Expiry used 30-day "months" (drifting ~5 days a year) and expired immediately when months = 0. Calendar months are used and 0 means "does not expire".
- Department filter in get_compliance_stats only filtered totals; expiring/expired counts now follow the filter.
- PHP warnings ("property on null") on the Add staff form; staff search was case-sensitive on PostgreSQL and did not escape % and _.
- User pickers were capped at 500/1000 users and could drop the user being edited; they now always include the selected user and exclude guest/suspended users.
- A user could hold two active staff records, giving arbitrary results and duplicate-key warnings; this is now refused.
- Deleting a department, position or competency left orphaned references. Positions with staff can no longer be deleted; department references are cleared; competency tracking rows and notifications are removed.
- Deleting one staff record wiped all of the user's tracking rows even if they had another staff record.
- Notification dates used the cron user's timezone; mails now use the recipient's timezone and the no-reply sender.
- MySQL/PostgreSQL-only SQL (LIMIT, CONCAT) replaced with portable DML calls.
- Teacher overview ran the full learner projection for every active staff member on every page load; it now shows the first 25 people with a link to the dashboard.
- Learner and teacher cards are fully translatable; card CSS moved into styles.css instead of a <link> inside the page body; removed a Google Fonts @import and defined missing CSS variables.
- Inline JavaScript strings are JSON-encoded (apostrophes in translations broke the positions page).
- index.php now loads lib.php itself; removed the unnecessary lib/externallib.php includes.
- The predefined web service is no longer enabled for all users by default.
- Removed unused AMD modules (they called non-existent ajax actions) and unused templates.
- PHPUnit: tests use attributes (no PHPUnit 11 deprecations), skip cleanly without mod_certificatepro, and new regression tests cover the fixes above.

## [1.0.20] - 2026-10-12

- Graduate the approved learner and oversight cards to native Moodle rendering and progressive enhancement.
- Authorise explicit subject reads without changing the authenticated user; preserve the full My Training table.
- Separate assignment submission/marking and finished quiz evidence from official completion, with effective dates.
- Restrict certificate actions to existing PDF files and the actor's Certificate Pro permissions.

## [1.0.18] - 2026-10-12

- Preserve compliance counts after expiry cron, including soon-to-expired transitions.
- Sync all applicable course-to-competency mappings for active staff.
- Provision authorised manual course enrolment on staff assignment and position changes.
- Produce real XLSX exports, consistent department filters and formula-safe cells.
- Retry failed notification submissions without prematurely marking them sent.
- Replace the incorrect no-personal-data declaration with privacy discovery/export/deletion.
- Restrict the learner dashboard to their own requirements; enforce staff mutation capabilities.
- Add the integrated My Training view for course, pathway and certification evidence.
- Use Moodle's namespaced external API classes for report exports.

## [v1.0.3] - 2025-12-23

### Security
- Added Privacy API provider for GDPR compliance

# Changelog - Training Matrix Local Plugin

All notable changes to this plugin will be documented in this file.

## [1.0.2] - 2025-12-22

### Changed
- Added official Moodle 5.x compatibility declaration (`$plugin->supported = [400, 500]`)



## [1.0.0] - 2025-12-20

### Added
- Initial release
- Training compliance tracking
- Staff training matrix management
- Integration with block_trainingmatrix and block_trainingmatrix_teacher
- Moodle 4.0+ compatibility
