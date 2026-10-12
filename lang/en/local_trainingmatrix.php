<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * local_trainingmatrix file.
 *
 * @package    local_trainingmatrix
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'AI Training Matrix';
$string['trainingmatrix'] = 'AI Training Matrix';
$string['trainingmatrix:manage'] = 'Manage AI Training Matrix';
$string['trainingmatrix:view'] = 'View AI Training Matrix';
$string['trainingmatrix:viewall'] = 'View all staff compliance';
$string['trainingmatrix:assign'] = 'Assign staff to positions';

// Navigation
$string['dashboard'] = 'Dashboard';
$string['departments'] = 'Departments';
$string['positions'] = 'Positions';
$string['competencies'] = 'Competencies';
$string['staff'] = 'Staff';
$string['reports'] = 'Reports';
$string['settings'] = 'Settings';
$string['notifications'] = 'Notifications';

// Dashboard
$string['dashboard_title'] = 'AI Training Matrix Dashboard';
$string['compliance_overview'] = 'Compliance Overview';
$string['total_staff'] = 'Total Staff';
$string['fully_compliant'] = 'Fully Compliant';
$string['expiring_soon'] = 'Expiring Soon';
$string['overdue'] = 'Overdue';
$string['not_started'] = 'Not Started';
$string['in_progress'] = 'In Progress';

// Departments
$string['add_department'] = 'Add Department';
$string['edit_department'] = 'Edit Department';
$string['delete_department'] = 'Delete Department';
$string['department_name'] = 'Department Name';
$string['parent_department'] = 'Parent Department';
$string['department_manager'] = 'Department Manager';
$string['no_departments'] = 'No departments configured';
$string['department_saved'] = 'Department saved successfully';
$string['department_deleted'] = 'Department deleted';

// Positions
$string['add_position'] = 'Add Position';
$string['edit_position'] = 'Edit Position';
$string['delete_position'] = 'Delete Position';
$string['position_name'] = 'Position Name';
$string['position_level'] = 'Level';
$string['position_department'] = 'Department';
$string['required_competencies'] = 'Required Competencies';
$string['no_positions'] = 'No positions configured';
$string['position_saved'] = 'Position saved successfully';
$string['position_deleted'] = 'Position deleted';

// Competencies
$string['add_competency'] = 'Add Competency';
$string['edit_competency'] = 'Edit Competency';
$string['delete_competency'] = 'Delete Competency';
$string['competency_name'] = 'Competency Name';
$string['competency_code'] = 'Code';
$string['competency_category'] = 'Category';
$string['linked_course'] = 'Linked Course';
$string['expiry_months'] = 'Expiry (months)';
$string['notify_days'] = 'Notify (days before)';
$string['mandatory'] = 'Mandatory';
$string['optional'] = 'Optional';
$string['no_competencies'] = 'No competencies configured';
$string['competency_saved'] = 'Competency saved successfully';
$string['competency_deleted'] = 'Competency deleted';
$string['select_course'] = 'Select a course...';

// Staff
$string['add_staff'] = 'Add Staff Member';
$string['edit_staff'] = 'Edit Staff Member';
$string['assign_position'] = 'Assign Position';
$string['staff_member'] = 'Staff Member';
$string['employee_id'] = 'Employee ID';
$string['start_date'] = 'Start Date';
$string['end_date'] = 'End Date';
$string['direct_manager'] = 'Direct Manager';
$string['staff_status'] = 'Status';
$string['status_active'] = 'Active';
$string['status_inactive'] = 'Inactive';
$string['status_terminated'] = 'Terminated';
$string['no_staff'] = 'No staff assigned';
$string['staff_saved'] = 'Staff record saved';
$string['select_user'] = 'Select user...';
$string['select_position'] = 'Select position...';
$string['select_department'] = 'Select department...';

// Competency Status
$string['status_not_started'] = 'Not Started';
$string['status_in_progress'] = 'In Progress';
$string['status_completed'] = 'Completed';
$string['status_expired'] = 'Expired';
$string['status_expiring_soon'] = 'Expiring Soon';
$string['status_valid'] = 'Valid';
$string['status_needs_redo'] = 'Needs Redo';

// Reports
$string['compliance_report'] = 'Compliance Report';
$string['staff_report'] = 'Staff Training Report';
$string['department_report'] = 'Department Report';
$string['expiry_report'] = 'Expiry Report';
$string['export_csv'] = 'Export CSV';
$string['export_excel'] = 'Export Excel';
$string['filter'] = 'Filter';
$string['search'] = 'Search';
$string['all_departments'] = 'All Departments';
$string['all_positions'] = 'All Positions';
$string['all_statuses'] = 'All Statuses';
$string['completion_date'] = 'Completion Date';
$string['expiry_date'] = 'Expiry Date';
$string['progress'] = 'Progress';
$string['actions'] = 'Actions';

// Notifications
$string['notification_expiry_warning'] = 'Training Expiry Warning';
$string['notification_expired'] = 'Training Expired';
$string['notification_new_requirement'] = 'New Training Requirement';
$string['notification_subject_expiry'] = 'Your {$a->competency} training expires in {$a->days} days';
$string['notification_subject_expired'] = 'Your {$a->competency} training has expired';
$string['notification_subject_new'] = 'New training requirement: {$a->competency}';
$string['notification_body_expiry'] = 'Your "{$a->competency}" training will expire on {$a->date}. Please complete the refresher course "{$a->course}" before this date.';
$string['notification_body_expired'] = 'Your "{$a->competency}" training expired on {$a->date}. Please complete the refresher course "{$a->course}" as soon as possible to maintain compliance.';
$string['notification_body_new'] = 'You have been assigned a new training requirement: "{$a->competency}". Please complete the course "{$a->course}" by {$a->date}.';

// Categories
$string['category_safety'] = 'Safety';
$string['category_technical'] = 'Technical';
$string['category_leadership'] = 'Leadership';
$string['category_compliance'] = 'Compliance';
$string['category_professional'] = 'Professional Development';
$string['category_induction'] = 'Induction';
$string['category_other'] = 'Other';

// Tooltips
$string['tooltip_expiry_months'] = 'Number of months after course completion before retraining is required';
$string['tooltip_notify_days'] = 'Number of days before expiry to send notification to staff member';
$string['tooltip_mandatory'] = 'Mandatory competencies must be completed for compliance';
$string['tooltip_progress'] = 'Course completion progress percentage';
$string['tooltip_valid'] = 'Training is current and valid';
$string['tooltip_expired'] = 'Training has expired and needs to be redone';
$string['tooltip_expiring'] = 'Training will expire soon - action required';

// Actions
$string['view_details'] = 'View Details';
$string['enrol_course'] = 'Enrol in Course';
$string['view_course'] = 'View Course';
$string['send_reminder'] = 'Send Reminder';
$string['mark_complete'] = 'Mark Complete';
$string['reset_progress'] = 'Reset Progress';
$string['bulk_assign'] = 'Bulk Assign';
$string['import_staff'] = 'Import Staff';

// Errors
$string['error_department_required'] = 'Department name is required';
$string['error_position_required'] = 'Position name is required';
$string['error_competency_required'] = 'Competency name is required';
$string['error_course_required'] = 'A linked course is required';
$string['error_user_required'] = 'Please select a user';
$string['error_position_required_staff'] = 'Please select a position';

// Success messages
$string['changes_saved'] = 'Changes saved successfully';
$string['item_deleted'] = 'Item deleted successfully';
$string['notifications_sent'] = 'Notifications sent successfully';
$string['staff_enrolled'] = 'Staff enrolled in required courses';

// Stats
$string['compliance_rate'] = 'Compliance Rate';
$string['training_completed'] = 'Training Completed';
$string['training_pending'] = 'Training Pending';
$string['avg_completion_time'] = 'Avg. Completion Time';
$string['days'] = 'days';

// Scheduled tasks
$string['task_check_expiry'] = 'Check training expiry dates';
$string['task_send_notifications'] = 'Send training notifications';
$string['task_sync_completions'] = 'Sync course completions';

// Privacy
$string['privacy:metadata:local_tm_staff'] = 'Staff position assignments';
$string['privacy:metadata:local_tm_completions'] = 'Training completion records';
$string['privacy:metadata:local_tm_notifications'] = 'Notification history';
$string['no_position_assigned'] = 'You are not assigned to a position.';
$string['assignment_access_blocked'] = 'Cannot automatically grant access to course {$a}. Check suspended enrolments, prerequisites and the manual enrolment method. The staff assignment was not saved.';
$string['privacy:metadata:local_tm_departments'] = 'Department manager assignments';
$string['privacy:metadata:field'] = 'An identifier, assignment detail, training outcome, notification or timestamp associated with the user.';

// AI Settings
$string['api_settings'] = 'AI API Settings';
$string['api_settings_desc'] = 'Configure the connection to Essay Grader AI for generating competencies.';
$string['api_url'] = 'API URL';
$string['api_url_desc'] = 'The base URL for the Essay Grader AI API.';
$string['site_id'] = 'Site ID';
$string['site_id_desc'] = 'Your unique Site ID from lms-labs.com';
$string['api_key'] = 'API Key';
$string['api_key_desc'] = 'Your API Key from lms-labs.com';
$string['credits_settings'] = 'Credit Settings';
$string['credits_settings_desc'] = 'Configure credit usage for AI features.';
$string['credits_per_position'] = 'Credits per Position';
$string['credits_per_position_desc'] = 'Number of credits consumed when generating AI competencies for a position.';

// AI Generation
$string['ai_generate_competencies'] = 'AI Generate Competencies';
$string['generating_competencies'] = 'Generating competencies...';
$string['competencies_generated'] = 'Competencies generated successfully';
$string['plugin_not_configured'] = 'Plugin not configured. Go to Site admin → Plugins → Local plugins → AI Training Matrix to set API credentials.';
$string['insufficient_credits'] = 'Insufficient credits. Required: {$a->required}, Available: {$a->available}';
$string['credits_available'] = 'Credits available';
$string['credits_required'] = 'Credits required';
$string['confirm_ai_generate'] = 'This will use {$a} credits to generate competencies for this position. Continue?';
$string['no_credits_info'] = 'Configure API settings in plugin settings to enable AI generation.';

// Read-only unified learner presentation.
$string['learner_mytraining'] = 'My Training';
$string['learner_intro'] = 'Your courses, programs, certifications and certificate documents in one place. Due dates and expiry dates are shown separately.';
$string['learner_filtertraining'] = 'Filter my training';
$string['learner_search'] = 'Search training';
$string['learner_searchhelp'] = 'Search by name, source or status.';
$string['learner_kind'] = 'Type';
$string['learner_allkinds'] = 'All types';
$string['learner_applyfilters'] = 'Apply filters';
$string['learner_clearfilters'] = 'Clear filters';
$string['learner_course'] = 'Course';
$string['learner_program'] = 'Program';
$string['learner_certification'] = 'Certification';
$string['learner_certificate'] = 'Certificate document';
$string['learner_name'] = 'Name';
$string['learner_source'] = 'Source';
$string['learner_status'] = 'Status';
$string['learner_due'] = 'Due date';
$string['learner_expires'] = 'Expiry date';
$string['learner_action'] = 'Next action';
$string['learner_nodate'] = 'Not set';
$string['learner_noaction'] = 'No action listed';
$string['learner_traininglist'] = 'Your training and certificate documents';
$string['learner_empty'] = 'No training or certificate documents are available to display yet. Your course enrolments will appear here even if you do not have a Matrix position.';
$string['learner_noresults'] = 'No training matches these filters. Try another search or clear the filters to see all your training.';
$string['learner_resultcount'] = 'Showing {$a->start}–{$a->end} of {$a->total} items';
$string['learner_priorityintro'] = 'Up to five items, with next actions and earliest due dates shown first.';
$string['learner_viewall'] = 'View all my training';
