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
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$reporttype = optional_param('report', 'compliance', PARAM_ALPHA);
$export = optional_param('export', '', PARAM_ALPHA);

echo html_writer::start_div('tm-reports tm-animate-in');

// Report type selector.
echo html_writer::start_div('tm-card-header', ['style' => 'padding: 0; margin-bottom: 24px; border: none;']);
echo html_writer::tag(
    'h3',
    html_writer::tag('i', '', ['class' => 'fa fa-chart-bar mr-2']) .
    get_string('reports', 'local_trainingmatrix'),
    ['class' => 'tm-card-title']
);
echo html_writer::end_div();

// Report tabs.
$reporttabs = [
    'compliance' => ['icon' => 'fa-clipboard-check', 'label' => get_string('compliance_report', 'local_trainingmatrix')],
    'expiry' => ['icon' => 'fa-clock', 'label' => get_string('expiry_report', 'local_trainingmatrix')],
    'department' => ['icon' => 'fa-building', 'label' => get_string('department_report', 'local_trainingmatrix')],
];

echo html_writer::start_div('', ['style' => 'display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap;']);
foreach ($reporttabs as $key => $info) {
    $active = $reporttype === $key ? 'tm-btn-primary' : 'tm-btn-secondary';
    echo html_writer::link(
        new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'reports', 'report' => $key]),
        html_writer::tag('i', '', ['class' => 'fa ' . $info['icon'] . ' mr-2']) . $info['label'],
        ['class' => 'tm-btn ' . $active]
    );
}
echo html_writer::end_div();

switch ($reporttype) {
    case 'compliance':
        // Full compliance matrix report.
        echo html_writer::start_div('tm-card');
        echo html_writer::start_div('tm-card-header');
        echo html_writer::tag('h4', 'Staff Compliance Matrix', ['class' => 'tm-card-title']);
        echo html_writer::link(
            new moodle_url('/local/trainingmatrix/export.php', ['report' => 'compliance', 'format' => 'csv']),
            html_writer::tag('i', '', ['class' => 'fa fa-download mr-2']) . get_string('export_csv', 'local_trainingmatrix'),
            ['class' => 'tm-btn tm-btn-secondary tm-btn-sm']
        );
        echo html_writer::end_div();
        echo html_writer::start_div('tm-card-body');

        [$scopesql, $scopeparams] = $scope->staff_condition('s');
        $stafflist = $DB->get_records_sql("
            SELECT s.*, u.firstname, u.lastname, u.email, p.name as position_name, d.name as department_name
            FROM {local_trainingmatrix_staff} s
            JOIN {user} u ON u.id = s.userid
            LEFT JOIN {local_trainingmatrix_pos} p ON p.id = s.positionid
            LEFT JOIN {local_trainingmatrix_dept} d ON d.id = s.departmentid
            WHERE s.status = 'active' AND $scopesql
            ORDER BY d.name, u.lastname
        ", $scopeparams);

        if (empty($stafflist)) {
            echo html_writer::tag('p', 'No active staff members found.', ['class' => 'text-muted']);
        } else {
            echo html_writer::start_div('tm-table-container');
            echo html_writer::start_tag('table', ['class' => 'tm-table']);
            echo html_writer::start_tag('thead');
            echo html_writer::start_tag('tr');
            echo html_writer::tag('th', 'Staff Member');
            echo html_writer::tag('th', 'Position');
            echo html_writer::tag('th', 'Required');
            echo html_writer::tag('th', 'Completed');
            echo html_writer::tag('th', 'Expiring');
            echo html_writer::tag('th', 'Expired');
            echo html_writer::tag('th', 'Compliance');
            echo html_writer::tag('th', 'Status');
            echo html_writer::end_tag('tr');
            echo html_writer::end_tag('thead');
            echo html_writer::start_tag('tbody');

            foreach ($stafflist as $staff) {
                $compliance = local_trainingmatrix_get_staff_compliance($staff->userid);
                if (!$compliance) {
                    continue;
                }

                $stats = $compliance['stats'];
                $comprate = $stats['compliance_rate'];
                $progressclass = $comprate >= 100 ? 'success' : ($comprate >= 70 ? 'warning' : 'danger');

                $overallstatus = 'Compliant';
                $overallbadge = 'completed';
                if ($stats['expired'] > 0) {
                    $overallstatus = 'Non-Compliant';
                    $overallbadge = 'danger';
                } else if ($stats['expiring_soon'] > 0) {
                    $overallstatus = 'At Risk';
                    $overallbadge = 'warning';
                } else if ($comprate < 100) {
                    $overallstatus = 'In Progress';
                    $overallbadge = 'in_progress';
                }

                echo html_writer::start_tag('tr');
                echo html_writer::tag(
                    'td',
                    html_writer::tag('strong', s($staff->firstname . ' ' . $staff->lastname)) .
                    html_writer::tag('div', s($staff->email), ['style' => 'color: var(--tm-gray-500); font-size: 12px;'])
                );
                echo html_writer::tag('td', $staff->position_name ? format_string($staff->position_name) : '-');
                echo html_writer::tag('td', html_writer::tag('span', $stats['total'], ['class' => 'tm-badge info']));
                echo html_writer::tag('td', html_writer::tag('span', $stats['completed'], ['class' => 'tm-badge completed']));
                echo html_writer::tag('td', $stats['expiring_soon'] > 0 ?
                    html_writer::tag('span', $stats['expiring_soon'], ['class' => 'tm-badge warning']) : '-');
                echo html_writer::tag('td', $stats['expired'] > 0 ?
                    html_writer::tag('span', $stats['expired'], ['class' => 'tm-badge danger']) : '-');
                echo html_writer::tag(
                    'td',
                    html_writer::start_div('', ['style' => 'display: flex; align-items: center; gap: 8px;']) .
                    html_writer::start_div('tm-progress') .
                    html_writer::div('', 'tm-progress-bar ' . $progressclass, ['style' => 'width: ' . $comprate . '%;']) .
                    html_writer::end_div() .
                    html_writer::tag('span', $comprate . '%', ['class' => 'tm-progress-label']) .
                    html_writer::end_div()
                );
                echo html_writer::tag('td', html_writer::tag('span', $overallstatus, ['class' => 'tm-badge ' . $overallbadge]));
                echo html_writer::end_tag('tr');
            }

            echo html_writer::end_tag('tbody');
            echo html_writer::end_tag('table');
            echo html_writer::end_div();
        }

        echo html_writer::end_div();
        echo html_writer::end_div();
        break;

    case 'expiry':
        // Expiry report.
        echo html_writer::start_div('tm-card');
        echo html_writer::start_div('tm-card-header');
        echo html_writer::tag('h4', 'Training Expiry Report', ['class' => 'tm-card-title']);
        echo html_writer::end_div();
        echo html_writer::start_div('tm-card-body');

        $now = time();
        $thirtydays = $now + (30 * 24 * 60 * 60);
        $sixtydays = $now + (60 * 24 * 60 * 60);
        $ninetydays = $now + (90 * 24 * 60 * 60);

        // Expired.
        [$scopesql, $scopeparams] = $scope->user_condition('tc.userid');
        $expired = $DB->get_records_sql("
            SELECT tc.*, c.name as competency_name, c.code, u.firstname, u.lastname, u.email
            FROM {local_trainingmatrix_compl} tc
            JOIN {local_trainingmatrix_comp} c ON c.id = tc.competencyid
            JOIN {user} u ON u.id = tc.userid
            WHERE tc.status IN ('completed', 'expiring_soon', 'expired') AND tc.expirydate IS NOT NULL
              AND tc.expirydate < :now AND $scopesql
            ORDER BY tc.expirydate ASC
        ", ['now' => $now] + $scopeparams);

        // Expiring soon (next 30 days).
        [$scopesql, $scopeparams] = $scope->user_condition('tc.userid');
        $expiringsoon = $DB->get_records_sql("
            SELECT tc.*, c.name as competency_name, c.code, u.firstname, u.lastname, u.email
            FROM {local_trainingmatrix_compl} tc
            JOIN {local_trainingmatrix_comp} c ON c.id = tc.competencyid
            JOIN {user} u ON u.id = tc.userid
            WHERE tc.status IN ('completed', 'expiring_soon', 'expired') AND tc.expirydate IS NOT NULL
              AND tc.expirydate BETWEEN :now AND :soon AND $scopesql
            ORDER BY tc.expirydate ASC
        ", ['now' => $now, 'soon' => $thirtydays] + $scopeparams);

        // Summary stats.
        echo html_writer::start_div('tm-stats-grid', ['style' => 'margin-bottom: 24px;']);

        echo html_writer::start_div('tm-stat-card danger');
        echo html_writer::tag('div', count($expired), ['class' => 'tm-stat-value']);
        echo html_writer::tag('div', 'Expired', ['class' => 'tm-stat-label']);
        echo html_writer::end_div();

        echo html_writer::start_div('tm-stat-card warning');
        echo html_writer::tag('div', count($expiringsoon), ['class' => 'tm-stat-value']);
        echo html_writer::tag('div', 'Expiring (30 days)', ['class' => 'tm-stat-label']);
        echo html_writer::end_div();

        echo html_writer::end_div();

        // Expired table.
        if (!empty($expired)) {
            echo html_writer::tag(
                'h5',
                'Expired Training (' . count($expired) . ')',
                ['style' => 'margin: 24px 0 12px; color: var(--tm-danger);']
            );
            echo html_writer::start_div('tm-table-container');
            echo html_writer::start_tag('table', ['class' => 'tm-table']);
            echo html_writer::start_tag('thead');
            echo html_writer::start_tag('tr');
            echo html_writer::tag('th', 'Staff Member');
            echo html_writer::tag('th', 'Competency');
            echo html_writer::tag('th', 'Expired On');
            echo html_writer::tag('th', 'Days Overdue');
            echo html_writer::tag('th', 'Action');
            echo html_writer::end_tag('tr');
            echo html_writer::end_tag('thead');
            echo html_writer::start_tag('tbody');

            foreach ($expired as $e) {
                $daysoverdue = ceil(($now - $e->expirydate) / 86400);
                echo html_writer::start_tag('tr');
                echo html_writer::tag(
                    'td',
                    s($e->firstname . ' ' . $e->lastname) . html_writer::tag(
                        'div',
                        s($e->email),
                        ['style' => 'font-size: 12px; color: var(--tm-gray-500);']
                    )
                );
                echo html_writer::tag(
                    'td',
                    ($e->code ? html_writer::tag(
                        'span',
                        s($e->code),
                        ['class' => 'tm-badge info', 'style' => 'margin-right: 8px;']
                    ) : '') . format_string($e->competency_name)
                );
                echo html_writer::tag('td', userdate($e->expirydate, get_string('strftimedate', 'langconfig')));
                echo html_writer::tag('td', html_writer::tag('span', $daysoverdue . ' days', ['class' => 'tm-badge danger']));
                echo html_writer::tag(
                    'td',
                    html_writer::link(
                        new moodle_url(
                            '/local/trainingmatrix/index.php',
                            [
                                'tab' => 'reports',
                                'report' => 'expiry',
                                'action' => 'remind',
                                'cid' => $e->id,
                                'sesskey' => sesskey(),
                            ]
                        ),
                        get_string('send_reminder', 'local_trainingmatrix'),
                        ['class' => 'tm-btn tm-btn-secondary tm-btn-sm']
                    )
                );
                echo html_writer::end_tag('tr');
            }

            echo html_writer::end_tag('tbody');
            echo html_writer::end_tag('table');
            echo html_writer::end_div();
        }

        // Expiring soon table.
        if (!empty($expiringsoon)) {
            echo html_writer::tag(
                'h5',
                'Expiring Within 30 Days (' . count($expiringsoon) . ')',
                ['style' => 'margin: 24px 0 12px; color: var(--tm-warning);']
            );
            echo html_writer::start_div('tm-table-container');
            echo html_writer::start_tag('table', ['class' => 'tm-table']);
            echo html_writer::start_tag('thead');
            echo html_writer::start_tag('tr');
            echo html_writer::tag('th', 'Staff Member');
            echo html_writer::tag('th', 'Competency');
            echo html_writer::tag('th', 'Expires On');
            echo html_writer::tag('th', 'Days Remaining');
            echo html_writer::tag('th', 'Action');
            echo html_writer::end_tag('tr');
            echo html_writer::end_tag('thead');
            echo html_writer::start_tag('tbody');

            foreach ($expiringsoon as $e) {
                $daysremaining = ceil(($e->expirydate - $now) / 86400);
                $badgeclass = $daysremaining <= 7 ? 'danger' : 'warning';
                echo html_writer::start_tag('tr');
                echo html_writer::tag(
                    'td',
                    s($e->firstname . ' ' . $e->lastname) . html_writer::tag(
                        'div',
                        s($e->email),
                        ['style' => 'font-size: 12px; color: var(--tm-gray-500);']
                    )
                );
                echo html_writer::tag(
                    'td',
                    ($e->code ? html_writer::tag(
                        'span',
                        s($e->code),
                        ['class' => 'tm-badge info', 'style' => 'margin-right: 8px;']
                    ) : '') . format_string($e->competency_name)
                );
                echo html_writer::tag('td', userdate($e->expirydate, get_string('strftimedate', 'langconfig')));
                echo html_writer::tag(
                    'td',
                    html_writer::tag('span', $daysremaining . ' days', ['class' => 'tm-badge ' . $badgeclass])
                );
                echo html_writer::tag(
                    'td',
                    html_writer::link(
                        new moodle_url(
                            '/local/trainingmatrix/index.php',
                            [
                                'tab' => 'reports',
                                'report' => 'expiry',
                                'action' => 'remind',
                                'cid' => $e->id,
                                'sesskey' => sesskey(),
                            ]
                        ),
                        get_string('send_reminder', 'local_trainingmatrix'),
                        ['class' => 'tm-btn tm-btn-secondary tm-btn-sm']
                    )
                );
                echo html_writer::end_tag('tr');
            }

            echo html_writer::end_tag('tbody');
            echo html_writer::end_tag('table');
            echo html_writer::end_div();
        }

        if (empty($expired) && empty($expiringsoon)) {
            echo html_writer::start_div('tm-empty');
            echo html_writer::tag('i', '', ['class' => 'fa fa-check-circle tm-empty-icon', 'style' => 'color: var(--tm-success);']);
            echo html_writer::tag('div', 'All training is up to date!', ['class' => 'tm-empty-title']);
            echo html_writer::tag('div', 'No expired or expiring training found.', ['class' => 'tm-empty-text']);
            echo html_writer::end_div();
        }

        echo html_writer::end_div();
        echo html_writer::end_div();
        break;

    case 'department':
        // Department summary report.
        echo html_writer::start_div('tm-card');
        echo html_writer::start_div('tm-card-header');
        echo html_writer::tag('h4', 'Department Compliance Summary', ['class' => 'tm-card-title']);
        echo html_writer::end_div();
        echo html_writer::start_div('tm-card-body');

        [$defsql, $defparams] = $scope->definition_condition('d');
        [$scopesql, $scopeparams] = $scope->staff_condition('s');
        $departments = $DB->get_records_sql("
            SELECT d.*,
                   (SELECT COUNT(*) FROM {local_trainingmatrix_staff} s
                     WHERE s.departmentid = d.id AND s.status = 'active' AND $scopesql) as staff_count
            FROM {local_trainingmatrix_dept} d
            WHERE $defsql
            ORDER BY d.name
        ", $scopeparams + $defparams);
        if (!$scope->sees_all()) {
            // Managers and client views list only departments that hold someone they can see.
            $departments = array_filter($departments, static fn($d) => (int)$d->staff_count > 0);
        }

        if (empty($departments)) {
            echo html_writer::tag('p', 'No departments configured.', ['class' => 'text-muted']);
        } else {
            echo html_writer::start_div(
                '',
                ['style' => 'display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;']
            );

            foreach ($departments as $dept) {
                // Calculate department compliance.
                [$deptscopesql, $deptscopeparams] = $scope->staff_condition('s');
                $staffindept = $DB->get_records_sql(
                    "SELECT s.* FROM {local_trainingmatrix_staff} s
                    WHERE s.departmentid = :deptid AND s.status = 'active' AND $deptscopesql",
                    ['deptid' => $dept->id] + $deptscopeparams
                );
                $totalcompliance = 0;
                $staffcount = 0;
                $expired = 0;

                foreach ($staffindept as $staff) {
                    $compliance = local_trainingmatrix_get_staff_compliance($staff->userid);
                    if ($compliance) {
                        $totalcompliance += $compliance['stats']['compliance_rate'];
                        $expired += $compliance['stats']['expired'];
                        $staffcount++;
                    }
                }

                $avgcompliance = $staffcount > 0 ? round($totalcompliance / $staffcount) : 0;
                $compclass = $avgcompliance >= 90 ? 'success' : ($avgcompliance >= 70 ? 'warning' : 'danger');

                echo html_writer::start_div('tm-card', ['style' => 'border-left: 4px solid var(--tm-' . $compclass . ');']);
                echo html_writer::start_div('tm-card-body');

                echo html_writer::tag('h5', format_string($dept->name), ['style' => 'margin: 0 0 16px;']);

                echo html_writer::start_div('', ['style' => 'display: flex; justify-content: space-between; align-items: center;']);
                echo html_writer::start_div();
                echo html_writer::tag('div', $dept->staff_count, ['style' => 'font-size: 24px; font-weight: 700;']);
                echo html_writer::tag('div', 'Staff Members', ['style' => 'font-size: 12px; color: var(--tm-gray-500);']);
                echo html_writer::end_div();

                echo html_writer::start_div('', ['style' => 'text-align: right;']);
                echo html_writer::tag(
                    'div',
                    $avgcompliance . '%',
                    ['style' => 'font-size: 24px; font-weight: 700; color: var(--tm-' . $compclass . ');']
                );
                echo html_writer::tag('div', 'Avg Compliance', ['style' => 'font-size: 12px; color: var(--tm-gray-500);']);
                echo html_writer::end_div();
                echo html_writer::end_div();

                if ($expired > 0) {
                    echo html_writer::tag(
                        'div',
                        html_writer::tag('i', '', ['class' => 'fa fa-exclamation-triangle mr-2']) .
                        $expired . ' expired training items',
                        [
                            'style' => 'margin-top: 12px; padding: 8px 12px; background: rgba(239, 68, 68, 0.1); '
                                . 'color: var(--tm-danger); border-radius: 6px; font-size: 13px;',
                        ]
                    );
                }

                echo html_writer::end_div();
                echo html_writer::end_div();
            }

            echo html_writer::end_div();
        }

        echo html_writer::end_div();
        echo html_writer::end_div();
        break;
}

echo html_writer::end_div();
