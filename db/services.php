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
 * AI Training Matrix HCM v1.0.0 - External services
 *
 * @package    local_trainingmatrix
 * @copyright  2025 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_trainingmatrix_get_compliance_stats' => [
        'classname'     => 'local_trainingmatrix\external\get_compliance_stats',
        'methodname'    => 'execute',
        'description'   => 'Get compliance statistics',
        'type'          => 'read',
        'ajax'          => true,
        'capabilities'  => 'local/trainingmatrix:viewall',
    ],
    'local_trainingmatrix_get_staff_completions' => [
        'classname'     => 'local_trainingmatrix\external\get_staff_completions',
        'methodname'    => 'execute',
        'description'   => 'Get staff competency completions',
        'type'          => 'read',
        'ajax'          => true,
        'capabilities'  => 'local/trainingmatrix:view',
    ],
    'local_trainingmatrix_generate_competencies' => [
        'classname'     => 'local_trainingmatrix\external\generate_competencies',
        'methodname'    => 'execute',
        'description'   => 'AI generate competencies for position',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'local/trainingmatrix:manage',
    ],
    'local_trainingmatrix_export_report' => [
        'classname'     => 'local_trainingmatrix\external\export_report',
        'methodname'    => 'execute',
        'description'   => 'Export compliance report',
        'type'          => 'read',
        'ajax'          => false,
        'capabilities'  => 'local/trainingmatrix:viewall',
    ],
];

$services = [
    'AI Training Matrix API' => [
        'functions' => [
            'local_trainingmatrix_get_compliance_stats',
            'local_trainingmatrix_get_staff_completions',
            'local_trainingmatrix_generate_competencies',
            'local_trainingmatrix_export_report',
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
    ],
];
