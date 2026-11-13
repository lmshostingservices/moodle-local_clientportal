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
 * Create or edit one of the client's courses.
 *
 * Custom data: categories (id => name), formats (name => label), new (bool).
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_form extends \moodleform {
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

        $mform->addElement('text', 'fullname', get_string('coursefullname', 'local_clientportal'), ['size' => 60]);
        $mform->setType('fullname', PARAM_TEXT);
        $mform->addRule('fullname', null, 'required', null, 'client');
        $mform->addRule('fullname', get_string('maximumchars', '', 254), 'maxlength', 254, 'client');
        $mform->addElement('text', 'shortname', get_string('courseshortname', 'local_clientportal'), ['size' => 30]);
        $mform->setType('shortname', PARAM_TEXT);
        $mform->addHelpButton('shortname', 'courseshortname', 'local_clientportal');
        $mform->addElement('select', 'category', get_string('coursecategory', 'local_clientportal'), $cd['categories']);
        if (!empty($cd['new'])) {
            $mform->addElement('select', 'format', get_string('courseformat', 'local_clientportal'), $cd['formats']);
        }
        $mform->addElement('date_selector', 'startdate', get_string('coursestart', 'local_clientportal'));
        $mform->addElement(
            'editor',
            'summary_editor',
            get_string('coursesummary', 'local_clientportal'),
            ['rows' => 5],
            ['maxfiles' => 0, 'noclean' => false]
        );
        $mform->setType('summary_editor', PARAM_CLEANHTML);
        $mform->addElement(
            'advcheckbox',
            'autoenrol',
            get_string('autoenrol', 'local_clientportal'),
            get_string('autoenrol_desc', 'local_clientportal')
        );
        $mform->addElement(
            'advcheckbox',
            'visible',
            get_string('coursevisible', 'local_clientportal'),
            get_string('coursevisible_desc', 'local_clientportal')
        );
        $mform->setDefault('visible', 1);
        $this->add_action_buttons(true, get_string(!empty($cd['new']) ? 'createcourse' : 'savechanges', !empty($cd['new'])
            ? 'local_clientportal' : 'moodle'));
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
        if (!isset($this->_customdata['categories'][$data['category']])) {
            $errors['category'] = get_string('error_notyourcategory', 'local_clientportal');
        }
        $shortname = trim((string)$data['shortname']);
        if ($shortname !== '' && !\local_clientportal\courses::shortname_free($shortname, (int)$data['id'])) {
            $errors['shortname'] = get_string('error_shortnametaken', 'local_clientportal', s($shortname));
        }
        return $errors;
    }
}
