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
 * AI Training Matrix HCM v1.0.8: Session Lock Fix - Version file
 * Production-grade Moodle HCM plugin for compliance tracking
 *
 * @package    local_trainingmatrix
 * @copyright  2025 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_trainingmatrix';
$plugin->version   = 2026072300;
$plugin->requires  = 2022041900; // Moodle 4.0+
$plugin->supported  = [400, 500];  // Moodle 4.0 to 5.x
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.0.17'; // FIX-CURL-BATCH: ajax.php and generate_competencies.php switched from raw curl_init() to Moodle \curl wrapper + write_close(). No DB schema changes.
