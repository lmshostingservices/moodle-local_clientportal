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

use local_clientportal\manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Create or edit a client.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client_form extends \moodleform {
    /**
     * Form fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $editing = !empty($this->_customdata['id']);
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'name', get_string('clientname', 'local_clientportal'), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        if ($editing) {
            $mform->addElement('static', 'cohortname', get_string('cohort', 'local_clientportal'));
        } else {
            $options = [0 => get_string('newcohort', 'local_clientportal')] + manager::available_cohorts();
            $mform->addElement('select', 'cohortid', get_string('cohort', 'local_clientportal'), $options);
            $mform->addHelpButton('cohortid', 'cohort', 'local_clientportal');
        }

        $mform->addElement('text', 'abn', get_string('abn', 'local_clientportal'), ['size' => 20]);
        $mform->setType('abn', PARAM_TEXT);
        $mform->addElement('text', 'contactname', get_string('contactname', 'local_clientportal'), ['size' => 40]);
        $mform->setType('contactname', PARAM_TEXT);
        $mform->addElement('text', 'contactemail', get_string('contactemail', 'local_clientportal'), ['size' => 40]);
        $mform->setType('contactemail', PARAM_EMAIL);
        $mform->addElement('text', 'phone', get_string('phone', 'local_clientportal'), ['size' => 20]);
        $mform->setType('phone', PARAM_TEXT);
        $mform->addElement('textarea', 'notes', get_string('notes', 'local_clientportal'), ['rows' => 3, 'cols' => 50]);
        $mform->setType('notes', PARAM_TEXT);
        $mform->addElement(
            'advcheckbox',
            'active',
            get_string('active', 'local_clientportal'),
            get_string('active_desc', 'local_clientportal')
        );
        $mform->setDefault('active', 1);

        $this->add_action_buttons(true, get_string('saveclient', 'local_clientportal'));
    }
}
