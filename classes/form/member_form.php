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
 * Add client staff: an existing account, or a new one created and emailed a password.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class member_form extends \moodleform {
    /**
     * Form fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $new = !empty($this->_customdata['new']);
        $mform->addElement('hidden', 'client');
        $mform->setType('client', PARAM_INT);
        $mform->addElement('hidden', 'mode', $new ? 'new' : 'existing');
        $mform->setType('mode', PARAM_ALPHA);

        if ($new) {
            $mform->addElement('text', 'firstname', get_string('firstname'), ['size' => 30]);
            $mform->setType('firstname', PARAM_NOTAGS);
            $mform->addRule('firstname', null, 'required', null, 'client');
            $mform->addElement('text', 'lastname', get_string('lastname'), ['size' => 30]);
            $mform->setType('lastname', PARAM_NOTAGS);
            $mform->addRule('lastname', null, 'required', null, 'client');
            $mform->addElement('text', 'email', get_string('email'), ['size' => 40]);
            $mform->setType('email', PARAM_EMAIL);
            $mform->applyFilter('email', 'trim');
            $mform->addRule('email', null, 'required', null, 'client');
            $mform->addRule('email', null, 'email', null, 'client');
        } else {
            $mform->addElement('autocomplete', 'userid', get_string('user'), [], [
                'ajax' => 'core_user/form_user_selector',
                'multiple' => false,
                'noselectionstring' => get_string('searchuser', 'local_clientportal'),
            ]);
            $mform->addRule('userid', null, 'required', null, 'client');
        }
        $mform->addElement('select', 'role', get_string('role', 'local_clientportal'), [
            manager::ROLE_MANAGER => get_string('role_manager', 'local_clientportal'),
            manager::ROLE_REP => get_string('role_rep', 'local_clientportal'),
            manager::ROLE_TRAINER => get_string('role_trainer', 'local_clientportal'),
        ]);
        $mform->setDefault('role', manager::ROLE_MANAGER);
        $mform->addHelpButton('role', 'role', 'local_clientportal');
        $this->add_action_buttons(false, get_string($new ? 'createstaff' : 'addstaff', 'local_clientportal'));
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        global $DB, $CFG;
        $errors = parent::validation($data, $files);
        if (($data['mode'] ?? '') === 'new') {
            if (!validate_email($data['email'])) {
                $errors['email'] = get_string('invalidemail');
            } else if (
                empty($CFG->allowaccountssameemail) &&
                    $DB->record_exists_select(
                        'user',
                        'LOWER(email) = LOWER(:email) AND deleted = 0',
                        ['email' => $data['email']]
                    )
            ) {
                $errors['email'] = get_string('emailexists');
            }
        }
        return $errors;
    }
}
