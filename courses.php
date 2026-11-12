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
 * Client Portal - courses: the client's own categories and courses.
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

$categoryid = optional_param('category', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$scope = scope::require_portal(new moodle_url('/local/clientportal/courses.php', $categoryid ? ['category' => $categoryid] : []));
if (!courses::has($scope->client, courses::FEATURE_COURSES) || (!$scope->is_manager() && !$scope->is_trainer())) {
    redirect($scope->url('/local/clientportal/index.php'));
}
$baseurl = $scope->url('/local/clientportal/courses.php', $categoryid ? ['category' => $categoryid] : []);
if ($scope->is_manager()) {
    courses::ensure_category($scope->client);
}

// Category actions (managers).
if ($action && $scope->is_manager() && confirm_sesskey()) {
    $name = trim(optional_param('name', '', PARAM_TEXT));
    $target = required_param('target', PARAM_INT);
    if ($action === 'newcategory' && $name !== '') {
        courses::create_category($scope, $target, $name);
        redirect(
            $baseurl,
            get_string('categorycreated', 'local_clientportal', s($name)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    if ($action === 'renamecategory' && $name !== '') {
        courses::rename_category($scope, $target, $name);
        redirect(
            $baseurl,
            get_string('categoryrenamed', 'local_clientportal', s($name)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    if ($action === 'deletecategory') {
        try {
            courses::delete_category($scope, $target);
            redirect(
                $scope->url('/local/clientportal/courses.php'),
                get_string('categorydeleted', 'local_clientportal'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } catch (moodle_exception $e) {
            redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_WARNING);
        }
    }
    redirect($baseurl);
}

$options = courses::category_options($scope->client);
$list = courses::list($scope, $scope->is_trainer() ? courses::taught_by($scope, (int)$USER->id) : null);
if ($categoryid && !isset($options[$categoryid])) {
    $categoryid = 0;
}
$shown = $categoryid ? array_filter($list, fn($c) => (int)$c->category === $categoryid) : $list;

$actions = '';
if ($scope->is_manager()) {
    $actions = html_writer::link(
        $scope->url('/local/clientportal/courseedit.php', ['category' => $categoryid ?: (int)$scope->client->categoryid]),
        get_string('newcourse', 'local_clientportal'),
        ['class' => 'btn btn-primary']
    );
}
echo ui::header($scope, 'courses', get_string('nav_coursebuilder', 'local_clientportal'), $actions);

$visible = count(array_filter($list, fn($c) => $c->visible));
echo html_writer::start_div('cport-stats');
echo ui::stat(get_string('stat_courses', 'local_clientportal'), count($list), get_string(
    'stat_coursesvisible',
    'local_clientportal',
    $visible
), 'accent');
echo ui::stat(
    get_string('stat_enrolledlearners', 'local_clientportal'),
    array_sum(array_map(fn($c) => $c->learners, $list)),
    '',
    'info'
);
echo ui::stat(get_string('stat_categories', 'local_clientportal'), max(0, count($options) - 1));
echo ui::stat(
    get_string('stat_noteacher', 'local_clientportal'),
    count(array_filter($list, fn($c) => !$c->teachers)),
    '',
    count(array_filter($list, fn($c) => !$c->teachers)) ? 'warn' : 'neutral'
);
echo html_writer::end_div();

echo html_writer::start_div('cport-coursebuilder');

// Categories (left).
if ($scope->is_manager()) {
    $counts = [];
    foreach ($list as $c) {
        $counts[(int)$c->category] = ($counts[(int)$c->category] ?? 0) + 1;
    }
    $tree = html_writer::link(
        $scope->url('/local/clientportal/courses.php'),
        html_writer::span(get_string('allcourses', 'local_clientportal')) . html_writer::span(count($list), 'cport-count'),
        ['class' => 'cport-cat' . (!$categoryid ? ' active' : '')]
    );
    foreach ($options as $id => $label) {
        if ((int)$id === (int)$scope->client->categoryid) {
            continue;
        }
        $tree .= html_writer::link(
            $scope->url('/local/clientportal/courses.php', ['category' => $id]),
            html_writer::span(s($label)) . html_writer::span($counts[$id] ?? 0, 'cport-count'),
            ['class' => 'cport-cat' . ((int)$id === $categoryid ? ' active' : '')]
        );
    }
    $form = html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false), 'class' => 'cport-catform']);
    $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'newcategory']);
    $form .= html_writer::label(
        get_string('newcategory', 'local_clientportal'),
        'cport-newcat',
        true,
        ['class' => 'cport-field-label']
    );
    $form .= html_writer::select(
        $options,
        'target',
        $categoryid ?: (int)$scope->client->categoryid,
        false,
        ['class' => 'custom-select form-select mb-2', 'aria-label' => get_string('parentcategory', 'local_clientportal')]
    );
    $form .= html_writer::empty_tag('input', ['type' => 'text', 'name' => 'name', 'id' => 'cport-newcat', 'required' => 'required',
        'class' => 'form-control mb-2', 'placeholder' => get_string('categoryname', 'local_clientportal')]);
    $form .= html_writer::tag('button', get_string('addcategory', 'local_clientportal'), ['type' => 'submit',
        'class' => 'btn btn-outline-primary btn-sm']);
    $form .= html_writer::end_tag('form');
    if ($categoryid && $categoryid !== (int)$scope->client->categoryid) {
        $form .= html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false), 'class' => 'cport-catform']);
        $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'target', 'value' => $categoryid]);
        $form .= html_writer::label(
            get_string('renamecategory', 'local_clientportal'),
            'cport-rencat',
            true,
            ['class' => 'cport-field-label']
        );
        $form .= html_writer::empty_tag('input', ['type' => 'text', 'name' => 'name', 'id' => 'cport-rencat',
            'class' => 'form-control mb-2', 'value' => trim(str_replace('—', '', $options[$categoryid]))]);
        $form .= html_writer::tag('button', get_string('rename', 'local_clientportal'), ['type' => 'submit', 'name' => 'action',
            'value' => 'renamecategory', 'class' => 'btn btn-outline-secondary btn-sm me-1 mr-1']);
        $form .= html_writer::tag('button', get_string('deleteemptycategory', 'local_clientportal'), ['type' => 'submit',
            'name' => 'action', 'value' => 'deletecategory', 'class' => 'btn btn-outline-secondary btn-sm']);
        $form .= html_writer::end_tag('form');
    }
    echo ui::card(get_string('categories', 'local_clientportal'), html_writer::div($tree, 'cport-cats') .
        html_writer::div($form, 'cport-pad'));
}

// Courses (right).
if (!$shown) {
    $body = ui::empty(get_string($scope->is_trainer() ? 'nocoursestaught' : 'nocoursesyet', 'local_clientportal'));
} else {
    $body = '';
    foreach ($shown as $c) {
        $meta = [];
        $meta[] = s(trim(str_replace('—', '', $options[$c->category] ?? '')));
        $meta[] = get_string('learnerscount', 'local_clientportal', $c->learners);
        $meta[] = get_string('teacherscount', 'local_clientportal', $c->teachers);
        $badges = '';
        if (!$c->visible) {
            $badges .= html_writer::span(
                get_string('hiddenfromlearners', 'local_clientportal'),
                'cport-pill cport-pill-notstarted'
            );
        }
        if ($c->autoenrol) {
            $badges .= html_writer::span(get_string('everyoneenrolled', 'local_clientportal'), 'cport-pill cport-pill-inprogress');
        }
        if (!$c->teachers) {
            $badges .= html_writer::span(get_string('noteacheryet', 'local_clientportal'), 'cport-pill cport-pill-late');
        }
        $buttons = html_writer::link(
            $scope->url('/local/clientportal/coursemanage.php', ['id' => $c->id]),
            get_string('managecourse', 'local_clientportal'),
            ['class' => 'btn btn-sm btn-primary']
        ) . ' ' .
            html_writer::link(
                new moodle_url('/course/view.php', ['id' => $c->id]),
                get_string('opencourse', 'local_clientportal'),
                ['class' => 'btn btn-sm btn-outline-secondary']
            );
        $body .= html_writer::div(
            html_writer::div(
                html_writer::tag('h3', html_writer::link(
                    $scope->url('/local/clientportal/coursemanage.php', ['id' => $c->id]),
                    s(format_string($c->fullname))
                ), ['class' => 'cport-course-title']) .
                html_writer::div(implode(' · ', $meta), 'cport-muted') .
                ($badges ? html_writer::div($badges, 'cport-badges') : ''),
                'cport-course-main'
            ) . html_writer::div($buttons, 'cport-course-actions'),
            'cport-course-row'
        );
    }
}
echo ui::card($categoryid ? s($options[$categoryid]) : get_string('allcourses', 'local_clientportal'), $body);
echo html_writer::end_div();
echo ui::footer();
