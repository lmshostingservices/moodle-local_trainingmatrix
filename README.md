# AI Training Matrix

AI Training Matrix organises staff training requirements by department, position
and competency, and gives learners a personal My Training view of their courses
and available training evidence.

## Features

- Manage staff, departments, positions and competency requirements.
- Track training completion, expiry and compliance.
- Export filtered reports as CSV or Excel.
- Show a learner's own course, pathway and certification evidence when the
  corresponding optional plugins are installed.
- Provide privacy discovery, export and deletion support.

## Clients and line managers

- **Clients (optional).** Install the Client portal to train staff for several client organisations on one site. Departments, positions and competencies are either *Shared* (usable by every client, good for standard roles such as "WHS Induction") or belong to one client. A staff record belongs to one client, and can only use shared items or that client's own. Administrators pick a client in the header to work in it.
- **Line managers.** Set a staff member's *Direct manager*, or a department's *Manager*. That person then sees those staff (and everyone in the department and its sub-departments) in the Matrix: Staff, Reports, statistics, exports and the Staff Training Dashboard block. No role setup is needed; it comes from the `local/trainingmatrix:viewteam` capability that every user has by default. Remove it from the Authenticated user role to switch this off.

## Importing staff

Use the **Import** tab to add or update many staff from a CSV file. Identify each person by `username`, `email` or `useridnumber`, give their `position`, and optionally `employeeid`, `department`, `manager`, `startdate`, `enddate`, `status` and `client`. Every row is previewed before anything changes. Each imported person is enrolled in the courses their position requires. A template is linked on the Import tab.

## Automatic enrolment

When someone is saved or imported as active in a position, they are enrolled (manual enrolment) in every course linked to the position's competencies. When a position gains a competency, or a competency is linked to a course, everyone already in that position is enrolled too. Each course needs an enabled manual enrolment method. Suspended enrolments are never re-activated; those people are listed in a warning.

## Requirements

- Moodle 4.4 or later within the supported range declared in version.php.
- Appropriate management capabilities for staff and organisation changes.
- Training Pathways, Course Recertification and Certificate Pro are optional
  integrations; installing Matrix does not create or reset their records.

## Installation

1. Back up the Moodle database and plugin directory.
2. Install local_trainingmatrix in local/trainingmatrix using Moodle's plugin installer.
3. Complete the administrator upgrade screen.
4. Review plugin capabilities before delegating management access.
5. Optionally install the free learner and staff dashboard companion blocks.

## Usage

Administrators define departments, positions and their required competencies,
then associate staff and courses with those requirements. Review completion and
expiry reports through the plugin dashboard.

Learners open local/trainingmatrix/my.php or the learner block to see their own
training. Course links respect enrolment and access checks. Certificate links
require an accessible, valid stored document; the view does not generate awards.

## Pricing

The one-time Matrix unlock is 50 LMS Labs credits or US$5 on Moodle Marketplace.
AI generation is a separate operation charged at 10 credits per position.
The learner and manager companion blocks are free.

## Privacy and safety

Only authorised managers may change staff records. A learner cannot request
another learner's dashboard. Review retention requirements before deleting
personal training evidence. Optional certification integrations do not reset
course progress automatically.

## Licence

GNU GPL v3 or later.
