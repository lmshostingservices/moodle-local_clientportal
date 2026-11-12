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
 * Onboarding: a learner's role in the client (position, department, start date, manager) and their pathways.
 *
 * Custom data: positions (id => "Department: Position"), managers (id => name), pathways (id => name, those the
 * learner is not on yet), matrix (bool), canenrol (bool).
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class onboard_form extends \moodleform {
    /**
     * Form fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $cd = $this->_customdata;
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'client');
        $mform->setType('client', PARAM_INT);

        if (!empty($cd['matrix'])) {
            $mform->addElement('header', 'rolehdr', get_string('onboard_role', 'local_clientportal'));
            $mform->setExpanded('rolehdr');
            $positions = [0 => get_string('choosedots')] + $cd['positions'];
            $mform->addElement('select', 'positionid', get_string('position', 'local_clientportal'), $positions);
            $mform->addHelpButton('positionid', 'position', 'local_clientportal');
            $mform->addElement('date_selector', 'startdate', get_string('startdate', 'local_clientportal'));
            $managers = [0 => get_string('none')] + $cd['managers'];
            $mform->addElement('select', 'managerid', get_string('directmanager', 'local_clientportal'), $managers);
            $mform->addElement('text', 'employeeid', get_string('employeeid', 'local_clientportal'), ['size' => 20]);
            $mform->setType('employeeid', PARAM_NOTAGS);
        }

        if (!empty($cd['pathways'])) {
            $mform->addElement('header', 'pathwayhdr', get_string('onboard_pathways', 'local_clientportal'));
            $mform->setExpanded('pathwayhdr');
            $mform->addElement('static', 'pathwayintro', '', get_string('onboard_pathways_desc', 'local_clientportal'));
            if (!empty($cd['usual'])) {
                $lines = [];
                $names = $cd['positions'] ?? [];
                foreach ($cd['usual'] as $positionid => $paths) {
                    if (isset($names[$positionid])) {
                        $lines[] = \html_writer::tag('li', s($names[$positionid]) . ': ' . s(implode(', ', $paths)));
                    }
                }
                $mform->addElement(
                    'advcheckbox',
                    'usual',
                    get_string('usualpathways', 'local_clientportal'),
                    get_string('usualpathways_desc', 'local_clientportal') .
                        ($lines ? \html_writer::tag('ul', implode('', $lines), ['class' => 'cport-usual']) : '')
                );
                $mform->setDefault('usual', 1);
            }
            foreach ($cd['pathways'] as $id => $name) {
                $mform->addElement('advcheckbox', 'pathway[' . $id . ']', '', $name);
            }
            $mform->addElement(
                'date_selector',
                'targetdate',
                get_string('targetdate', 'local_clientportal'),
                ['optional' => true]
            );
        }
        if (!empty($cd['canenrol'])) {
            $mform->addElement('header', 'enrolhdr', get_string('onboard_courses', 'local_clientportal'));
            $mform->setExpanded('enrolhdr');
            $mform->addElement(
                'advcheckbox',
                'enrol',
                get_string('enrolrequired', 'local_clientportal'),
                get_string('enrolrequired_desc', 'local_clientportal')
            );
            $mform->setDefault('enrol', 1);
        }
        $this->add_action_buttons(true, get_string('saveonboarding', 'local_clientportal'));
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $cd = $this->_customdata;
        if (!empty($cd['matrix']) && empty($data['positionid'])) {
            $errors['positionid'] = get_string('required');
        }
        if (!empty($data['positionid']) && !isset($cd['positions'][$data['positionid']])) {
            $errors['positionid'] = get_string('error_badposition', 'local_clientportal');
        }
        return $errors;
    }
}
