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

namespace local_trainingmatrix\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/csvlib.class.php');

/**
 * Upload form for the staff CSV import.
 *
 * @package    local_trainingmatrix
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement(
            'filepicker',
            'csvfile',
            get_string('import_file', 'local_trainingmatrix'),
            null,
            ['accepted_types' => ['.csv', '.txt'], 'maxbytes' => 0]
        );
        $mform->addRule('csvfile', null, 'required');

        $mform->addElement(
            'select',
            'delimiter',
            get_string('import_delimiter', 'local_trainingmatrix'),
            \csv_import_reader::get_delimiter_list()
        );
        $mform->setDefault('delimiter', 'comma');

        $mform->addElement(
            'select',
            'encoding',
            get_string('import_encoding', 'local_trainingmatrix'),
            \core_text::get_encodings()
        );
        $mform->setDefault('encoding', 'UTF-8');

        if (\local_trainingmatrix\local\tenancy::clients()) {
            $mform->addElement(
                'select',
                'clientid',
                get_string('import_defaultclient', 'local_trainingmatrix'),
                \local_trainingmatrix\local\tenancy::staff_options()
            );
            $mform->setDefault('clientid', $this->_customdata['clientid'] ?? 0);
            $mform->addHelpButton('clientid', 'import_defaultclient', 'local_trainingmatrix');
        } else {
            $mform->addElement('hidden', 'clientid', 0);
        }
        $mform->setType('clientid', PARAM_INT);

        $mform->addElement('advcheckbox', 'createmissing', get_string('import_createmissing', 'local_trainingmatrix'));
        $mform->addHelpButton('createmissing', 'import_createmissing', 'local_trainingmatrix');

        $this->add_action_buttons(false, get_string('import_preview', 'local_trainingmatrix'));
    }
}
