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

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_trainingmatrix', get_string('pluginname', 'local_trainingmatrix'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_heading(
        'local_trainingmatrix/apiheading',
        get_string('api_settings', 'local_trainingmatrix'),
        get_string('api_settings_desc', 'local_trainingmatrix')
    ));

    $settings->add(new admin_setting_configtext(
        'local_trainingmatrix/apiurl',
        get_string('api_url', 'local_trainingmatrix'),
        get_string('api_url_desc', 'local_trainingmatrix'),
        'https://lms-labs.com'
    ));

    $settings->add(new admin_setting_configtext(
        'local_trainingmatrix/siteid',
        get_string('site_id', 'local_trainingmatrix'),
        get_string('site_id_desc', 'local_trainingmatrix'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_trainingmatrix/apikey',
        get_string('api_key', 'local_trainingmatrix'),
        get_string('api_key_desc', 'local_trainingmatrix'),
        ''
    ));

    $settings->add(new admin_setting_heading(
        'local_trainingmatrix/creditsheading',
        get_string('credits_settings', 'local_trainingmatrix'),
        get_string('credits_settings_desc', 'local_trainingmatrix')
    ));

    $settings->add(new admin_setting_heading(
        'local_trainingmatrix/approved_tariff',
        get_string('credits_per_position', 'local_trainingmatrix'),
        '10 credits per position generation. Enforced by the LMS Labs server.'
    ));
}
