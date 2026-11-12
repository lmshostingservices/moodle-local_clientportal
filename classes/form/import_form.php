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

namespace local_clientportal\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Upload an onboarding CSV.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_form extends \moodleform {
    /**
     * Form fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'client');
        $mform->setType('client', PARAM_INT);
        $mform->addElement(
            'filepicker',
            'csvfile',
            get_string('import_file', 'local_clientportal'),
            null,
            ['accepted_types' => ['.csv'], 'maxbytes' => 2097152]
        );
        $mform->addRule('csvfile', null, 'required', null, 'client');
        $this->add_action_buttons(false, get_string('import_check', 'local_clientportal'));
    }
}
