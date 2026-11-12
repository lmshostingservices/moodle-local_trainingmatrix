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

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

echo html_writer::start_div('tm-positions tm-animate-in');

// Header with add button
echo html_writer::start_div('tm-card-header', ['style' => 'padding: 0; margin-bottom: 24px; border: none;']);
echo html_writer::tag('h3', 
    html_writer::tag('i', '', ['class' => 'fa fa-user-tie mr-2']) . 
    get_string('positions', 'local_trainingmatrix'), 
    ['class' => 'tm-card-title']
);
echo html_writer::link(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'positions', 'action' => 'add']),
    html_writer::tag('i', '', ['class' => 'fa fa-plus mr-2']) . get_string('add_position', 'local_trainingmatrix'),
    ['class' => 'tm-btn tm-btn-primary']
);
echo html_writer::end_div();

// AI Generation Scripts
$credits_per_position = \local_trainingmatrix\local\ai_client::CREDITS_PER_POSITION;
$PAGE->requires->js_amd_inline("
require(['jquery'], function ($) {
    window.tmGenerateCompetencies = function (positionId) {
        if (!confirm(" . json_encode(get_string('confirm_ai_generate', 'local_trainingmatrix', $credits_per_position)) . ")) {
            return;
        }
        
        var btn = $('#ai-generate-' + positionId);
        var originalHtml = btn.html();
        btn.html('<i class=\"fa fa-spinner fa-spin mr-2\"></i>' + " . json_encode(s(get_string('generating_competencies', 'local_trainingmatrix'))) . ");
        btn.prop('disabled', true);
        
        $.ajax({
            url: M.cfg.wwwroot + '/local/trainingmatrix/ajax.php',
            method: 'POST',
            data: {
                action: 'generate',
                positionid: positionId,
                sesskey: M.cfg.sesskey
            },
            dataType: 'json',
            success: function (response) {
                if (response.ok) {
                    var msg = " . json_encode(get_string('competencies_generated', 'local_trainingmatrix')) . ";
                    if (response.created && response.created.length > 0) {
                        msg += '\\n\\nCreated: ' + response.created.join(', ');
                    }
                    if (response.linked && response.linked.length > 0) {
                        msg += '\\n\\nLinked existing: ' + response.linked.join(', ');
                    }
                    msg += '\\n\\nCredits used: ' + response.credits_used;
                    msg += '\\nCredits remaining: ' + response.credits_remaining;
                    alert(msg);
                    location.reload();
                } else {
                    alert('Error: ' + response.error);
                    btn.html(originalHtml);
                    btn.prop('disabled', false);
                }
            },
            error: function (xhr, status, error) {
                alert('Request failed: ' + error);
                btn.html(originalHtml);
                btn.prop('disabled', false);
            }
        });
    };
});
");

// Show form if adding/editing
if ($action === 'add' || $action === 'edit') {
    $position = $action === 'edit' ? $DB->get_record('local_tm_positions', ['id' => $id]) : null;
    // Offer shared definitions plus those of the position's own client (or the chosen client filter).
    $formclient = $position ? (int)$position->clientid : (int)$clientfilter;
    $defscope = $formclient ? \local_trainingmatrix\local\scope::for_client($formclient) : $scope;
    [$dsql, $dparams] = $defscope->definition_condition('d');
    $departments = $DB->get_records_sql("SELECT d.id, d.name, d.clientid FROM {local_tm_departments} d
                                          WHERE $dsql ORDER BY d.name", $dparams);
    [$csql, $cparams] = $defscope->definition_condition('c');
    $allCompetencies = $DB->get_records_sql("SELECT c.id, c.name, c.code, c.category, c.clientid
                                               FROM {local_tm_competencies} c
                                              WHERE $csql ORDER BY c.category, c.name", $cparams);
    $assignedComps = $action === 'edit' ? $DB->get_records('local_tm_pos_comps', ['positionid' => $id], '', 'competencyid') : [];
    $assignedCompIds = array_keys($assignedComps);
    
    echo html_writer::start_div('tm-card');
    echo html_writer::start_div('tm-card-header');
    echo html_writer::tag('h4', $action === 'edit' ? get_string('edit_position', 'local_trainingmatrix') : get_string('add_position', 'local_trainingmatrix'), ['class' => 'tm-card-title']);
    echo html_writer::end_div();
    echo html_writer::start_div('tm-card-body');
    
    $formUrl = new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'positions', 'action' => 'save', 'id' => $id, 'sesskey' => sesskey()]);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $formUrl->out(false)]);
    
    // Name
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('position_name', 'local_trainingmatrix') . ' *', ['class' => 'tm-form-label']);
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'name',
        'class' => 'tm-form-input',
        'required' => 'required',
        'value' => $position->name ?? '',
        'placeholder' => 'e.g. Site Supervisor, Safety Officer',
    ]);
    echo html_writer::end_div();
    
    echo local_trainingmatrix_client_form_group($formclient);

    // Description
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', 'Description', ['class' => 'tm-form-label']);
    echo html_writer::tag('textarea', s($position->description ?? ''), [
        'name' => 'description',
        'class' => 'tm-form-textarea',
        'rows' => 3,
    ]);
    echo html_writer::end_div();
    
    // Department
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('position_department', 'local_trainingmatrix'), ['class' => 'tm-form-label']);
    $deptOptions = ['' => get_string('select_department', 'local_trainingmatrix')];
    foreach ($departments as $d) {
        $deptOptions[$d->id] = local_trainingmatrix_definition_label($d);
    }
    echo html_writer::select($deptOptions, 'departmentid', $position->departmentid ?? '', false, ['class' => 'tm-form-select']);
    echo html_writer::end_div();
    
    // Level
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('position_level', 'local_trainingmatrix'), ['class' => 'tm-form-label']);
    $levelOptions = [];
    for ($i = 1; $i <= 10; $i++) {
        $levelOptions[$i] = "Level $i";
    }
    echo html_writer::select($levelOptions, 'level', $position->level ?? 1, false, ['class' => 'tm-form-select']);
    echo html_writer::end_div();
    
    // Required Competencies
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('required_competencies', 'local_trainingmatrix'), ['class' => 'tm-form-label']);
    echo html_writer::tag('p', 'Select the competencies required for this position:', ['style' => 'color: var(--tm-gray-500); font-size: 13px; margin-bottom: 12px;']);
    
    if (empty($allCompetencies)) {
        echo html_writer::tag('div', 'No competencies defined yet. ' . 
            html_writer::link(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'competencies', 'action' => 'add']), 'Add competencies first.'),
            ['style' => 'color: var(--tm-gray-500); font-style: italic;']
        );
    } else {
        $compsByCategory = [];
        foreach ($allCompetencies as $c) {
            $cat = $c->category ?: 'Other';
            if (!isset($compsByCategory[$cat])) {
                $compsByCategory[$cat] = [];
            }
            $compsByCategory[$cat][] = $c;
        }
        
        echo html_writer::start_div('', ['style' => 'max-height: 300px; overflow-y: auto; border: 1px solid var(--tm-gray-200); border-radius: 8px; padding: 16px;']);
        foreach ($compsByCategory as $category => $comps) {
            echo html_writer::tag('div', $category, ['style' => 'font-weight: 600; color: var(--tm-gray-700); margin: 12px 0 8px 0;']);
            foreach ($comps as $c) {
                $checked = in_array($c->id, $assignedCompIds);
                echo html_writer::start_div('', ['style' => 'display: flex; align-items: center; padding: 6px 0;']);
                echo html_writer::checkbox('competencies[]', $c->id, $checked, '', ['id' => 'comp_' . $c->id]);
                echo html_writer::tag('label', 
                    ($c->code ? html_writer::tag('span', s($c->code), ['class' => 'tm-badge info', 'style' => 'margin-right: 8px;']) : '') . local_trainingmatrix_definition_label($c),
                    ['for' => 'comp_' . $c->id, 'style' => 'margin-left: 8px; cursor: pointer;']
                );
                echo html_writer::end_div();
            }
        }
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
    
    // Buttons
    echo html_writer::start_div('', ['style' => 'display: flex; gap: 12px; margin-top: 24px;']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'tm-btn tm-btn-primary', 'value' => 'Save Position']);
    echo html_writer::link(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'positions']),
        'Cancel', ['class' => 'tm-btn tm-btn-secondary']);
    echo html_writer::end_div();
    
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_div();
    
} else {
    // List positions
    [$listsql, $listparams] = $scope->definition_condition('p');
    [$countsql, $countparams] = $scope->staff_condition('s');
    $positions = $DB->get_records_sql("
        SELECT p.*, 
               d.name as department_name,
               (SELECT COUNT(*) FROM {local_tm_staff} s WHERE s.positionid = p.id AND s.status = 'active' AND $countsql) as staff_count,
               (SELECT COUNT(*) FROM {local_tm_pos_comps} pc WHERE pc.positionid = p.id) as competency_count
        FROM {local_tm_positions} p
        LEFT JOIN {local_tm_departments} d ON d.id = p.departmentid
        WHERE $listsql
        ORDER BY d.name, p.level, p.name
    ", $countparams + $listparams);
    
    if (empty($positions)) {
        echo html_writer::start_div('tm-empty');
        echo html_writer::tag('i', '', ['class' => 'fa fa-user-tie tm-empty-icon']);
        echo html_writer::tag('div', get_string('no_positions', 'local_trainingmatrix'), ['class' => 'tm-empty-title']);
        echo html_writer::tag('div', 'Create positions to define job roles and their required competencies.', ['class' => 'tm-empty-text']);
        echo html_writer::link(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'positions', 'action' => 'add']),
            html_writer::tag('i', '', ['class' => 'fa fa-plus mr-2']) . get_string('add_position', 'local_trainingmatrix'),
            ['class' => 'tm-btn tm-btn-primary']
        );
        echo html_writer::end_div();
    } else {
        echo html_writer::start_div('tm-table-container');
        echo html_writer::start_tag('table', ['class' => 'tm-table']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Position');
        echo html_writer::tag('th', 'Department');
        echo html_writer::tag('th', 'Level');
        echo html_writer::tag('th', 'Competencies');
        echo html_writer::tag('th', 'Staff');
        echo html_writer::tag('th', 'Actions', ['style' => 'width: 100px;']);
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        
        foreach ($positions as $pos) {
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', 
                html_writer::tag('strong', format_string($pos->name)) .
                (\local_trainingmatrix\local\tenancy::clients() ? html_writer::tag('span', \local_trainingmatrix\local\tenancy::name((int)$pos->clientid), ['class' => 'tm-badge ' . ((int)$pos->clientid ? 'primary' : 'inactive'), 'style' => 'margin-left: 8px;']) : '') .
                ($pos->description ? html_writer::tag('div', s(shorten_text(html_to_text($pos->description, 0, false), 60)), ['style' => 'color: var(--tm-gray-500); font-size: 12px;']) : '')
            );
            echo html_writer::tag('td', $pos->department_name ? format_string($pos->department_name) : '-');
            echo html_writer::tag('td', html_writer::tag('span', 'Level ' . $pos->level, ['class' => 'tm-badge info']));
            echo html_writer::tag('td', html_writer::tag('span', $pos->competency_count, ['class' => 'tm-badge warning']));
            echo html_writer::tag('td', html_writer::tag('span', $pos->staff_count, ['class' => 'tm-badge primary']));
            echo html_writer::tag('td',
                html_writer::start_div('tm-dropdown') .
                html_writer::tag('button', html_writer::tag('i', '', ['class' => 'fa fa-ellipsis-v']), ['class' => 'tm-btn tm-btn-secondary tm-btn-icon tm-btn-sm']) .
                html_writer::start_div('tm-dropdown-menu') .
                html_writer::tag('button',
                    html_writer::tag('i', '', ['class' => 'fa fa-magic mr-2']) . get_string('ai_generate_competencies', 'local_trainingmatrix'),
                    ['class' => 'tm-dropdown-item', 'id' => 'ai-generate-' . $pos->id, 'onclick' => "tmGenerateCompetencies({$pos->id})"]
                ) .
                html_writer::link(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'positions', 'action' => 'edit', 'id' => $pos->id]),
                    html_writer::tag('i', '', ['class' => 'fa fa-edit mr-2']) . 'Edit',
                    ['class' => 'tm-dropdown-item']
                ) .
                html_writer::link(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'positions', 'action' => 'delete', 'id' => $pos->id, 'sesskey' => sesskey()]),
                    html_writer::tag('i', '', ['class' => 'fa fa-trash mr-2']) . 'Delete',
                    ['class' => 'tm-dropdown-item danger', 'onclick' => "return confirm('Are you sure?');"]
                ) .
                html_writer::end_div() .
                html_writer::end_div()
            );
            echo html_writer::end_tag('tr');
        }
        
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
        echo html_writer::end_div();
    }
}

echo html_writer::end_div();
