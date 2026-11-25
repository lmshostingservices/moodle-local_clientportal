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
 * Add a learner (one, or many pasted from a spreadsheet).
 *
 * Custom data: groups (id => name), courses (id => name), bulk (bool).
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class learner_form extends \moodleform {
    /**
     * Form fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $bulk = !empty($this->_customdata['bulk']);
        $groups = $this->_customdata['groups'] ?? [];
        $courses = $this->_customdata['courses'] ?? [];
        $str = fn(string $id) => get_string($id, 'local_clientportal');

        $mform->addElement('hidden', 'client');
        $mform->setType('client', PARAM_INT);
        $mform->addElement('hidden', 'bulk', $bulk ? 1 : 0);
        $mform->setType('bulk', PARAM_BOOL);

        if ($bulk) {
            $mform->addElement('textarea', 'rows', $str('addmany_rows'), ['rows' => 10, 'cols' => 80,
                'placeholder' => "firstname,lastname,email,username,password\nJo,Smith,jo.smith@example.com,,"]);
            $mform->setType('rows', PARAM_TEXT);
            $mform->addRule('rows', null, 'required', null, 'client');
            $mform->addHelpButton('rows', 'addmany_rows', 'local_clientportal');
        } else {
            $mform->addElement('text', 'firstname', get_string('firstname'), ['size' => 30]);
            $mform->setType('firstname', PARAM_NOTAGS);
            $mform->addElement('text', 'lastname', get_string('lastname'), ['size' => 30]);
            $mform->setType('lastname', PARAM_NOTAGS);
            $mform->addElement('text', 'email', get_string('email'), ['size' => 40]);
            $mform->setType('email', PARAM_EMAIL);
            $mform->addRule('email', null, 'required', null, 'client');
            $mform->addElement('static', 'existingnote', '', \html_writer::span($str('addlearner_existingnote'), 'cport-muted'));
            $mform->addElement('text', 'username', get_string('username'), ['size' => 30]);
            $mform->setType('username', PARAM_TEXT);
            $mform->addHelpButton('username', 'addlearner_username', 'local_clientportal');
        }

        $mform->addElement('advcheckbox', 'sendemail', $str('addlearner_sendemail'), $str('addlearner_sendemail_label'));
        $mform->setDefault('sendemail', 0);
        $mform->addHelpButton('sendemail', 'addlearner_sendemail', 'local_clientportal');

        if (!$bulk) {
            $mform->addElement('passwordunmask', 'password', $str('addlearner_password'), ['size' => 20]);
            $mform->setType('password', PARAM_TEXT);
            $mform->addHelpButton('password', 'addlearner_password', 'local_clientportal');
            $mform->hideIf('password', 'sendemail', 'checked');
        }
        $mform->addElement('advcheckbox', 'forcechange', $str('addlearner_forcechange'), $str('addlearner_forcechange_label'));
        $mform->setDefault('forcechange', 1);
        $mform->hideIf('forcechange', 'sendemail', 'checked');

        if ($groups) {
            $mform->addElement('autocomplete', 'groups', $str('groups'), $groups, ['multiple' => true]);
        }
        if ($courses) {
            $mform->addElement('autocomplete', 'courses', $str('addlearner_courses'), $courses, ['multiple' => true]);
            $mform->addHelpButton('courses', 'addlearner_courses', 'local_clientportal');
        }

        $this->add_action_buttons(true, $str($bulk ? 'addmany_submit' : 'addlearner_submit'));
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
        if (empty($data['bulk'])) {
            if (!empty($data['email']) && !validate_email($data['email'])) {
                $errors['email'] = get_string('invalidemail');
            }
            try {
                $user = !empty($data['email']) ? \local_clientportal\people::find_by_email($data['email']) : null;
            } catch (\moodle_exception $e) {
                $errors['email'] = $e->getMessage();
                return $errors;
            }
            if (!$user) {
                foreach (['firstname', 'lastname'] as $field) {
                    if (trim((string)($data[$field] ?? '')) === '') {
                        $errors[$field] = get_string('required');
                    }
                }
                if (empty($data['sendemail']) && (string)($data['password'] ?? '') !== '') {
                    $errmsg = '';
                    if (!check_password_policy($data['password'], $errmsg)) {
                        $errors['password'] = $errmsg;
                    }
                }
            }
        }
        return $errors;
    }
}
