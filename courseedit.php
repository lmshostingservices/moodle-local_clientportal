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
 * Client Portal - create or edit one of the client's courses.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\courses;
use local_clientportal\scope;
use local_clientportal\ui;

require(__DIR__ . '/../../config.php');

require_login(null, false);
// Access comes from client membership, checked by scope::require_portal(), so no require_capability() here.

$id = optional_param('id', 0, PARAM_INT);
$categoryid = optional_param('category', 0, PARAM_INT);
$scope = scope::require_portal(new moodle_url('/local/clientportal/courseedit.php', array_filter(['id' => $id,
    'category' => $categoryid])), true);
courses::require_feature($scope, courses::FEATURE_COURSES, true);
courses::ensure_category($scope->client);
$course = $id ? courses::require_course($scope, $id) : null;
$categories = courses::category_options($scope->client);
$formats = [];
foreach (core_component::get_plugin_list('format') as $name => $dir) {
    if (!get_config('format_' . $name, 'disabled') && !in_array($name, ['singleactivity', 'social'], true)) {
        $formats[$name] = get_string('pluginname', 'format_' . $name);
    }
}
$returnurl = $course ? $scope->url('/local/clientportal/coursemanage.php', ['id' => $course->id])
    : $scope->url('/local/clientportal/courses.php', $categoryid ? ['category' => $categoryid] : []);

$form = new local_clientportal\form\course_form(
    $scope->url('/local/clientportal/courseedit.php', array_filter(['id' => $id, 'category' => $categoryid])),
    ['categories' => $categories, 'formats' => $formats, 'new' => !$course]
);
if ($course) {
    $form->set_data([
        'id' => $course->id, 'fullname' => $course->fullname, 'shortname' => $course->shortname,
        'category' => $course->category, 'startdate' => $course->startdate, 'visible' => $course->visible,
        'summary_editor' => ['text' => $course->summary, 'format' => FORMAT_HTML],
        'autoenrol' => (int)$DB->record_exists('enrol', ['courseid' => $course->id, 'enrol' => 'cohort',
            'customint1' => $scope->client->cohortid, 'status' => ENROL_INSTANCE_ENABLED]),
        'client' => $scope->support ? $scope->id() : 0,
    ]);
} else {
    $default = isset($categories[$categoryid]) ? $categoryid : (int)$scope->client->categoryid;
    $form->set_data(['category' => $default, 'startdate' => time(), 'visible' => 1, 'autoenrol' => 0,
        'format' => isset($formats['aicourse']) ? 'aicourse' : 'topics', 'client' => $scope->support ? $scope->id() : 0]);
}
if ($form->is_cancelled()) {
    redirect($returnurl);
}
if ($data = $form->get_data()) {
    $data->summary = clean_text($data->summary_editor['text'] ?? '', FORMAT_HTML);
    if ($course) {
        courses::update($scope, (int)$course->id, $data);
        redirect($returnurl, get_string('coursesaved', 'local_clientportal'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    $new = courses::create($scope, $data);
    redirect(
        $scope->url('/local/clientportal/coursemanage.php', ['id' => $new->id]),
        get_string('coursecreated', 'local_clientportal', format_string($new->fullname)),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$back = html_writer::link($returnurl, get_string('back'), ['class' => 'btn btn-outline-secondary']);
echo ui::header($scope, 'courses', $course ? get_string('editcourse', 'local_clientportal', format_string($course->fullname))
    : get_string('newcourse', 'local_clientportal'), $back);
echo ui::card('', html_writer::div($form->render(), 'cport-pad cport-form'));
echo ui::footer();
