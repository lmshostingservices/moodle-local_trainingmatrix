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

$tasks = [
    [
        'classname' => 'local_trainingmatrix\task\check_expiry',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '6',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => 'local_trainingmatrix\task\send_notifications',
        'blocking' => 0,
        'minute' => '30',
        'hour' => '6',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => 'local_trainingmatrix\task\sync_completions',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '*/4',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
