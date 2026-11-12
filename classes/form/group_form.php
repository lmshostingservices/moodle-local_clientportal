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
 * Create or edit a client group.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class group_form extends \moodleform {
    /**
     * Form fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'client');
        $mform->setType('client', PARAM_INT);
        $mform->addElement('hidden', 'action', 'edit');
        $mform->setType('action', PARAM_ALPHA);

        $mform->addElement('text', 'name', get_string('groupname', 'local_clientportal'), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('text', 'costcentre', get_string('costcentre', 'local_clientportal'), ['size' => 30]);
        $mform->setType('costcentre', PARAM_TEXT);
        $mform->addHelpButton('costcentre', 'costcentre', 'local_clientportal');
        $mform->addRule('costcentre', get_string('maximumchars', '', 100), 'maxlength', 100, 'client');

        $mform->addElement('textarea', 'description', get_string('description'), ['rows' => 3, 'cols' => 50]);
        $mform->setType('description', PARAM_TEXT);

        $this->add_action_buttons(true, get_string('savegroup', 'local_clientportal'));
    }
}
