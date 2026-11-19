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
 * Create or edit a compliance rule.
 *
 * Custom data: courses (id => name), audiences (kind => [id => name]), competencies (id => name).
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_form extends \moodleform {
    /**
     * Form fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $courses = $this->_customdata['courses'];
        $audiences = $this->_customdata['audiences'];
        $competencies = $this->_customdata['competencies'] ?? [];

        foreach (['id' => 0, 'client' => 0] as $name => $default) {
            $mform->addElement('hidden', $name, $default);
            $mform->setType($name, PARAM_INT);
        }
        $mform->addElement('hidden', 'action', 'edit');
        $mform->setType('action', PARAM_ALPHA);

        $mform->addElement('header', 'whathdr', get_string('rule_what', 'local_clientportal'));
        $mform->addElement(
            'select',
            'courseid',
            get_string('rule_course', 'local_clientportal'),
            ['' => get_string('choosedots')] + $courses
        );
        $mform->addRule('courseid', null, 'required', null, 'client');
        $mform->addHelpButton('courseid', 'rule_course', 'local_clientportal');

        $kinds = ['all' => get_string('rule_audience_all', 'local_clientportal')];
        foreach (['group', 'department', 'position'] as $kind) {
            if (!empty($audiences[$kind])) {
                $kinds[$kind] = get_string('rule_audience_' . $kind, 'local_clientportal');
            }
        }
        $mform->addElement('select', 'audience', get_string('rule_audience', 'local_clientportal'), $kinds);
        foreach (['group', 'department', 'position'] as $kind) {
            if (!empty($audiences[$kind])) {
                $mform->addElement(
                    'select',
                    'audience_' . $kind,
                    get_string('rule_audience_' . $kind, 'local_clientportal'),
                    $audiences[$kind]
                );
                $mform->hideIf('audience_' . $kind, 'audience', 'neq', $kind);
            }
        }

        $mform->addElement('header', 'duehdr', get_string('rule_when', 'local_clientportal'));
        $mform->setExpanded('duehdr');
        $mform->addElement('select', 'duetype', get_string('rule_duetype', 'local_clientportal'), [
            'days' => get_string('rule_duetype_days', 'local_clientportal'),
            'date' => get_string('rule_duetype_date', 'local_clientportal'),
        ]);
        $mform->addElement('text', 'duedays', get_string('rule_duedays', 'local_clientportal'), ['size' => 5]);
        $mform->setType('duedays', PARAM_INT);
        $mform->setDefault('duedays', 30);
        $mform->addHelpButton('duedays', 'rule_duedays', 'local_clientportal');
        $mform->hideIf('duedays', 'duetype', 'neq', 'days');
        $mform->addElement('date_selector', 'duedate', get_string('rule_duedate', 'local_clientportal'));
        $mform->hideIf('duedate', 'duetype', 'neq', 'date');

        $mform->addElement('select', 'recurunit', get_string('rule_recur', 'local_clientportal'), [
            'none' => get_string('rule_recur_none', 'local_clientportal'),
            'months' => get_string('rule_recurunit_months', 'local_clientportal'),
            'days' => get_string('rule_recurunit_days', 'local_clientportal'),
            'yearly' => get_string('rule_recur_yearly', 'local_clientportal'),
        ]);
        $mform->setDefault('recurunit', 'months');
        $mform->addHelpButton('recurunit', 'rule_recur', 'local_clientportal');
        $mform->addElement('text', 'recurvalue', get_string('rule_recurvalue', 'local_clientportal'), ['size' => 5]);
        $mform->setType('recurvalue', PARAM_INT);
        $mform->setDefault('recurvalue', 12);
        $mform->hideIf('recurvalue', 'recurunit', 'in', ['none', 'yearly']);
        $mform->addElement('text', 'leaddays', get_string('rule_leaddays', 'local_clientportal'), ['size' => 5]);
        $mform->setType('leaddays', PARAM_INT);
        $mform->setDefault('leaddays', 30);
        $mform->addHelpButton('leaddays', 'rule_leaddays', 'local_clientportal');
        $mform->hideIf('leaddays', 'recurunit', 'eq', 'none');

        $mform->addElement('header', 'remindhdr', get_string('rule_remindersheader', 'local_clientportal'));
        $mform->setExpanded('remindhdr');
        $mform->addElement('text', 'reminders', get_string('rule_reminders', 'local_clientportal'), ['size' => 20]);
        $mform->setType('reminders', PARAM_TEXT);
        $mform->setDefault('reminders', '14, 3, 0');
        $mform->addHelpButton('reminders', 'rule_reminders', 'local_clientportal');
        $every = [0 => get_string('rule_overdue_never', 'local_clientportal')];
        foreach ([1, 3, 7, 14, 30] as $d) {
            $every[$d] = get_string($d === 1 ? 'rule_overdue_every1' : 'rule_overdue_every', 'local_clientportal', $d);
        }
        $mform->addElement('select', 'overdueevery', get_string('rule_overdueevery', 'local_clientportal'), $every);
        $mform->setDefault('overdueevery', 7);
        $after = [-1 => get_string('rule_manager_never', 'local_clientportal'),
            0 => get_string('rule_manager_now', 'local_clientportal')];
        foreach ([1, 3, 7, 14, 30] as $d) {
            $after[$d] = get_string($d === 1 ? 'rule_manager_after1' : 'rule_manager_after', 'local_clientportal', $d);
        }
        $mform->addElement('select', 'managerafter', get_string('rule_managerafter', 'local_clientportal'), $after);
        $mform->setDefault('managerafter', 7);
        $mform->addHelpButton('managerafter', 'rule_managerafter', 'local_clientportal');
        $mform->addElement('textarea', 'message', get_string('rule_message', 'local_clientportal'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('message', PARAM_TEXT);
        $mform->addHelpButton('message', 'rule_message', 'local_clientportal');

        if ($competencies) {
            $mform->addElement(
                'select',
                'competencyid',
                get_string('rule_competency', 'local_clientportal'),
                [0 => get_string('none')] + $competencies
            );
            $mform->addHelpButton('competencyid', 'rule_competency', 'local_clientportal');
        }
        $mform->addElement('advcheckbox', 'enabled', get_string('rule_enabled', 'local_clientportal'));
        $mform->setDefault('enabled', 1);

        $this->add_action_buttons(true, get_string('rule_save', 'local_clientportal'));
    }

    /**
     * Checks.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (($data['duetype'] ?? 'days') === 'days' && ((int)$data['duedays'] < 0 || (int)$data['duedays'] > 3650)) {
            $errors['duedays'] = get_string('rule_err_days', 'local_clientportal');
        }
        if (in_array($data['recurunit'] ?? 'none', ['days', 'months'], true) && (int)$data['recurvalue'] < 1) {
            $errors['recurvalue'] = get_string('rule_err_recur', 'local_clientportal');
        }
        if (
            trim((string)($data['reminders'] ?? '')) !== ''
                && \local_clientportal\compliance\rules::clean_reminders((string)$data['reminders']) === ''
        ) {
            $errors['reminders'] = get_string('rule_err_reminders', 'local_clientportal');
        }
        return $errors;
    }
}
