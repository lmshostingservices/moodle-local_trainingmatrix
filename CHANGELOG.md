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
