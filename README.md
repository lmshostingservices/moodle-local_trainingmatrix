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
