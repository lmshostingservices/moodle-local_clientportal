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
 * Client Portal - one of the client's courses: teachers, learners and enrolment.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\courses;
use local_clientportal\manager;
use local_clientportal\report;
use local_clientportal\scope;
use local_clientportal\ui;

require(__DIR__ . '/../../config.php');

require_login(null, false);
// Access comes from client membership, checked by scope::require_portal(), so no require_capability() here.

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$scope = scope::require_portal(new moodle_url('/local/clientportal/coursemanage.php', ['id' => $id]));
$course = courses::require_course($scope, $id);
if ($scope->is_trainer() && !in_array($id, courses::taught_by($scope, (int)$USER->id), true)) {
    throw new moodle_exception('error_notyourcourse', 'local_clientportal');
}
if (!$scope->is_manager() && !$scope->is_trainer()) {
    redirect($scope->url('/local/clientportal/index.php'));
}
$baseurl = $scope->url('/local/clientportal/coursemanage.php', ['id' => $id]);
$canmanage = $scope->is_manager() && courses::has($scope->client, courses::FEATURE_COURSES);
$canteachers = $scope->is_manager() && courses::has($scope->client, courses::FEATURE_TEACHERS);

if ($action && confirm_sesskey()) {
    $ok = \core\output\notification::NOTIFY_SUCCESS;
    switch ($action) {
        case 'addteacher':
            $userid = required_param('teacher', PARAM_INT);
            courses::add_teacher($scope, $id, $userid, optional_param('editing', 1, PARAM_BOOL));
            redirect($baseurl, get_string('teacheradded', 'local_clientportal'), null, $ok);
            break;
        case 'removeteacher':
            courses::remove_teacher($scope, $id, required_param('user', PARAM_INT));
            redirect($baseurl, get_string('teacherremoved', 'local_clientportal'), null, $ok);
            break;
        case 'enrol':
            $userids = optional_param_array('users', [], PARAM_INT);
            if ($groupid = optional_param('group', 0, PARAM_INT)) {
                manager::get_group($scope->id(), $groupid);
                $userids = array_merge($userids, $DB->get_fieldset_select(
                    'local_clientportal_groupmem',
                    'userid',
                    'groupid = ?',
                    [$groupid]
                ));
            }
            $userids = array_filter($userids, fn($u) => scope::is_learner($scope->id(), (int)$u));
            $n = $userids ? courses::enrol_learners($scope, $id, $userids) : 0;
            redirect($baseurl, get_string('learnersenrolled', 'local_clientportal', $n), null, $ok);
            break;
        case 'unenrol':
            courses::unenrol_learner($scope, $id, required_param('user', PARAM_INT));
            redirect($baseurl, get_string('learnerunenrolled', 'local_clientportal'), null, $ok);
            break;
        case 'autoenrol':
            courses::require_feature($scope, courses::FEATURE_COURSES, true);
            $on = optional_param('on', 0, PARAM_BOOL);
            courses::set_autoenrol($scope, $id, $on);
            redirect($baseurl, get_string($on ? 'autoenrolon' : 'autoenroloff', 'local_clientportal'), null, $ok);
            break;
    }
    redirect($baseurl);
}

$report = new report($scope);
$rows = $report->enrolments(null, null, $id);
$report->add_names($rows);
$teachers = courses::teachers($id);
$auto = $DB->record_exists('enrol', ['courseid' => $id, 'enrol' => 'cohort', 'customint1' => $scope->client->cohortid,
    'status' => ENROL_INSTANCE_ENABLED]);
$manualusers = [];
foreach ($DB->get_records('enrol', ['courseid' => $id, 'enrol' => 'manual']) as $inst) {
    foreach ($DB->get_fieldset_select('user_enrolments', 'userid', 'enrolid = ?', [$inst->id]) as $u) {
        $manualusers[(int)$u] = true;
    }
}

/**
 * A small POST form with one button.
 *
 * @param moodle_url $url
 * @param array $fields
 * @param string $label
 * @param string $class
 * @return string
 */
function local_clientportal_button_form(moodle_url $url, array $fields, string $label, string $class): string {
    $html = html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-inline']);
    foreach ($fields + ['sesskey' => sesskey()] as $k => $v) {
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
    }
    $html .= html_writer::tag('button', $label, ['type' => 'submit', 'class' => $class]);
    return $html . html_writer::end_tag('form');
}

$actions = html_writer::link(
    new moodle_url('/course/view.php', ['id' => $id]),
    get_string('opencourse', 'local_clientportal'),
    ['class' => 'btn btn-primary']
);
if ($canmanage) {
    $actions .= ' ' . html_writer::link(
        $scope->url('/local/clientportal/courseedit.php', ['id' => $id]),
        get_string('coursesettings', 'local_clientportal'),
        ['class' => 'btn btn-outline-secondary']
    );
}
$actions .= ' ' . html_writer::link($scope->url('/local/clientportal/courses.php'), get_string(
    'nav_coursebuilder',
    'local_clientportal'
), ['class' => 'btn btn-outline-secondary']);
echo ui::header($scope, 'courses', format_string($course->fullname), $actions);

$done = count(array_filter($rows, fn($r) => $r->status === report::COMPLETED));
$going = count(array_filter($rows, fn($r) => $r->status === report::INPROGRESS));
echo html_writer::start_div('cport-stats');
echo ui::stat(get_string('stat_learners', 'local_clientportal'), count($rows), $auto ? get_string(
    'everyoneenrolled',
    'local_clientportal'
) : '', 'accent');
echo ui::stat(get_string('status_completed', 'local_clientportal'), $done, '', 'good');
echo ui::stat(get_string('status_inprogress', 'local_clientportal'), $going, '', 'info');
echo ui::stat(
    get_string('status_notstarted', 'local_clientportal'),
    count($rows) - $done - $going,
    '',
    count($rows) - $done - $going ? 'warn' : 'neutral'
);
echo ui::stat(get_string('stat_teachers', 'local_clientportal'), count($teachers), '', count($teachers) ? 'neutral' : 'warn');
echo html_writer::end_div();

echo html_writer::start_div('cport-grid');

// Teachers.
$body = '';
foreach ($teachers as $t) {
    $role = get_string($t->roleshortname === 'editingteacher' ? 'teacher_editing' : 'teacher_marking', 'local_clientportal');
    $remove = $canteachers ? local_clientportal_button_form(
        $baseurl,
        ['action' => 'removeteacher', 'user' => $t->id],
        get_string('remove'),
        'btn btn-sm btn-outline-secondary'
    ) : '';
    $body .= html_writer::div(
        html_writer::div(html_writer::div(fullname($t), 'cport-name') . html_writer::div(s($role), 'cport-muted')) . $remove,
        'cport-person'
    );
}
if (!$teachers) {
    $body .= ui::empty(get_string('noteacheryet_desc', 'local_clientportal'));
}
if ($canteachers) {
    $options = array_diff_key(courses::teacher_options($scope), $teachers);
    if ($options) {
        $f = html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false), 'class' => 'cport-addteacher']);
        $f .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $f .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'addteacher']);
        $f .= html_writer::label(
            get_string('addteacher', 'local_clientportal'),
            'cport-teacher',
            true,
            ['class' => 'cport-field-label']
        );
        $f .= html_writer::select(
            $options,
            'teacher',
            '',
            false,
            ['id' => 'cport-teacher', 'class' => 'custom-select form-select']
        );
        $f .= html_writer::select(
            [1 => get_string('teacher_editing', 'local_clientportal'),
            0 => get_string('teacher_marking', 'local_clientportal')],
            'editing',
            1,
            false,
            ['class' => 'custom-select form-select', 'aria-label' => get_string('teacherrole', 'local_clientportal')]
        );
        $f .= html_writer::tag('button', get_string('add'), ['type' => 'submit', 'class' => 'btn btn-primary']);
        $f .= html_writer::end_tag('form');
        $body .= $f;
    } else if (!courses::teacher_options($scope)) {
        $body .= html_writer::div(
            get_string('notrainersyet', 'local_clientportal') . ' ' .
            html_writer::link($scope->url('/local/clientportal/team.php'), get_string('nav_team', 'local_clientportal')),
            'cport-muted mt-2'
        );
    }
}
echo ui::card(get_string('teachers', 'local_clientportal'), html_writer::div($body, 'cport-pad'));

// Enrol.
if ($canmanage) {
    $body = html_writer::div(
        html_writer::div(
            html_writer::div(get_string('autoenrol', 'local_clientportal'), 'cport-name') .
            html_writer::div(get_string($auto ? 'autoenrol_on' : 'autoenrol_off', 'local_clientportal'), 'cport-muted')
        ) . local_clientportal_button_form(
            $baseurl,
            ['action' => 'autoenrol', 'on' => $auto ? 0 : 1],
            get_string($auto ? 'turnoff' : 'turnon', 'local_clientportal'),
            'btn btn-sm ' . ($auto ? 'btn-outline-secondary'
            : 'btn-primary')
        ),
        'cport-person'
    );
    $enrolled = array_flip(array_map(fn($r) => (int)$r->userid, $rows));
    $candidates = [];
    foreach ($report->learners(null, '', 'name', 0, 0) as $u) {
        if (!isset($enrolled[(int)$u->id]) && !$u->suspended) {
            $candidates[$u->id] = fullname($u);
        }
    }
    $f = html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false)]);
    $f .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $f .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'enrol']);
    $groups = [0 => get_string('nogroupchosen', 'local_clientportal')];
    foreach (manager::get_groups($scope->id()) as $g) {
        $groups[$g->id] = get_string('wholegroup', 'local_clientportal', format_string($g->name));
    }
    if ($candidates) {
        $f .= html_writer::div(get_string('enrollearners', 'local_clientportal'), 'cport-field-label mt-3');
        $picks = '';
        foreach ($candidates as $uid => $name) {
            $picks .= html_writer::tag('label', html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'users[]',
                'value' => $uid]) . html_writer::span(s($name)), ['class' => 'cport-pick']);
        }
        $f .= html_writer::div($picks, 'cport-picklist cport-picklist-sm');
    }
    if (count($groups) > 1) {
        $f .= html_writer::select($groups, 'group', 0, false, ['class' => 'custom-select form-select mt-2',
            'aria-label' => get_string('group', 'local_clientportal')]);
    }
    if ($candidates || count($groups) > 1) {
        $f .= html_writer::div(html_writer::tag('button', get_string('enrol', 'enrol'), ['type' => 'submit',
            'class' => 'btn btn-primary']), 'mt-2');
    } else {
        $f .= html_writer::div(get_string('everyoneisenrolled', 'local_clientportal'), 'cport-muted mt-3');
    }
    $f .= html_writer::end_tag('form');
    echo ui::card(get_string('enrolment', 'local_clientportal'), html_writer::div($body . $f, 'cport-pad'));
}
echo html_writer::end_div();

// Learners.
if ($rows) {
    $table = new html_table();
    $table->attributes['class'] = 'table cport-table';
    $table->head = [get_string('learner', 'local_clientportal'), get_string('status', 'local_clientportal'),
        get_string('progress', 'local_clientportal'), get_string('enrolled', 'local_clientportal'),
        get_string('lastaccess', 'local_clientportal'), ''];
    foreach ($rows as $r) {
        $name = $scope->is_trainer() ? s($r->fullname) : html_writer::link($scope->url(
            '/local/clientportal/learner.php',
            ['id' => $r->userid, 'course' => $id]
        ), s($r->fullname), ['class' => 'cport-name']);
        $remove = $canmanage && isset($manualusers[(int)$r->userid])
            ? local_clientportal_button_form($baseurl, ['action' => 'unenrol', 'user' => $r->userid], get_string(
                'unenrol',
                'enrol'
            ), 'btn btn-sm btn-outline-secondary') : '';
        $table->data[] = [$name, ui::status($r->status), ui::progress($r->percent), ui::date($r->enrolled),
            ui::date($r->lastaccess), $remove];
    }
    $body = html_writer::div(html_writer::table($table), 'cport-table-wrap');
} else {
    $body = ui::empty(get_string('nolearnersincourse', 'local_clientportal'));
}
echo ui::card(get_string('nav_learners', 'local_clientportal'), $body);
echo ui::footer();
