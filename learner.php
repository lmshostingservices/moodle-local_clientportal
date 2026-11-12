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
 * Client Portal - one learner: their courses, and drill-down into a course's activities.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\certificates;
use local_clientportal\manager;
use local_clientportal\report;
use local_clientportal\scope;
use local_clientportal\ui;

require(__DIR__ . '/../../config.php');

require_login(null, false);
// Access comes from client membership, checked by scope::require_portal(), so no require_capability() here.

$userid = required_param('id', PARAM_INT);
$courseid = optional_param('course', 0, PARAM_INT);
$params = ['id' => $userid] + ($courseid ? ['course' => $courseid] : []);
$scope = scope::require_portal(new moodle_url('/local/clientportal/learner.php', $params));
$learner = $scope->require_learner($userid);
$report = new report($scope);
$rows = $report->enrolments([$userid]);
$groups = $report->user_groups([$userid])[$userid] ?? [];
$showemail = (bool)get_config('local_clientportal', 'showemail');

$back = html_writer::link(
    $scope->url('/local/clientportal/learners.php'),
    get_string('backtolearners', 'local_clientportal'),
    ['class' => 'btn btn-outline-secondary']
);
echo ui::header($scope, 'learners', fullname($learner), $back);

// Profile summary.
$completed = count(array_filter($rows, fn($r) => $r->status === report::COMPLETED));
$items = [];
if ($showemail) {
    $items[get_string('email')] = s($learner->email);
}
$tags = '';
foreach ($groups as $g) {
    $tags .= html_writer::span(s($g), 'cport-tag');
}
$items[get_string('groups', 'local_clientportal')] = $tags ?: '–';
$items[get_string('nav_courses', 'local_clientportal')] = count($rows);
$items[get_string('status_completed', 'local_clientportal')] = $completed;
$items[get_string('lastlogin', 'local_clientportal')] = ui::date($learner->lastaccess ? (int)$learner->lastaccess : null, true);
if ($learner->suspended) {
    $items[get_string('account', 'local_clientportal')] = html_writer::span(
        get_string('suspended', 'local_clientportal'),
        'cport-pill cport-pill-suspended'
    );
}
$dl = '';
foreach ($items as $label => $value) {
    $dl .= html_writer::div(html_writer::tag('dt', s($label)) . html_writer::tag('dd', $value));
}
echo ui::card('', html_writer::tag('dl', $dl, ['class' => 'cport-profile mb-0']));

$certs = certificates::for_learner($userid, array_map(fn($r) => (int)$r->courseid, $rows));

/**
 * Certificate links for a course.
 *
 * @param array $items
 * @param scope $scope
 * @param int $userid
 * @return string
 */
function local_clientportal_cert_links(array $items, scope $scope, int $userid): string {
    $out = [];
    foreach ($items as $c) {
        if ($c->state === 'locked') {
            $out[] = html_writer::span(s($c->name) . ': ' . get_string('cert_locked', 'local_clientportal'), 'cport-muted');
            continue;
        }
        $url = $scope->url('/local/clientportal/certificate.php', ['user' => $userid, 'type' => $c->type, 'id' => $c->id]);
        $label = $c->state === 'issued'
            ? get_string('downloadcert', 'local_clientportal', s($c->name))
            : get_string('issuecert', 'local_clientportal', s($c->name));
        $link = html_writer::link(
            $url,
            $label,
            ['class' => 'btn btn-sm ' . ($c->state === 'issued' ? 'btn-primary' : 'btn-outline-primary')]
        );
        if ($c->state === 'issued' && $c->issued) {
            $link .= html_writer::span(get_string('issuedon', 'local_clientportal', userdate(
                $c->issued,
                get_string('strftimedate', 'langconfig')
            )), 'cport-cell-date');
        }
        $out[] = html_writer::div($link, 'mb-1');
    }
    return implode('', $out);
}

if (!$courseid) {
    if (!$rows) {
        echo ui::card(
            get_string('nav_courses', 'local_clientportal'),
            ui::empty(get_string('nolearnercourses', 'local_clientportal'))
        );
        echo ui::footer();
        exit;
    }
    $table = new html_table();
    $table->attributes['class'] = 'table cport-table';
    $table->head = [get_string('course'), get_string('status', 'local_clientportal'), get_string('progress', 'local_clientportal'),
        get_string('enrolled', 'local_clientportal'), get_string('completeddate', 'local_clientportal'),
        get_string('grade', 'local_clientportal'), get_string('lastaccess', 'local_clientportal'),
        get_string('certificates', 'local_clientportal')];
    foreach ($rows as $r) {
        $name = html_writer::link(
            $scope->url('/local/clientportal/learner.php', ['id' => $userid, 'course' => $r->courseid]),
            s($r->coursename),
            ['class' => 'cport-name']
        );
        if ($r->enrolstatus !== report::ENROL_ACTIVE) {
            $name .= html_writer::div(html_writer::span(
                get_string('enrol_' . $r->enrolstatus, 'local_clientportal'),
                'cport-pill cport-pill-' . $r->enrolstatus
            ));
        }
        $name .= html_writer::div(
            get_string('activitiescount', 'local_clientportal', ['done' => $r->done, 'total' => $r->total]),
            'cport-muted'
        );
        $table->data[] = [
            $name,
            ui::status($r->status),
            ui::progress($r->percent),
            ui::date($r->enrolled),
            ui::date($r->completed),
            $r->grade !== '' ? s($r->grade) : html_writer::span('–', 'text-muted'),
            ui::date($r->lastaccess),
            !empty($certs[$r->courseid]) ? local_clientportal_cert_links($certs[$r->courseid], $scope, $userid)
                : html_writer::span('–', 'text-muted'),
        ];
    }
    echo ui::card(
        get_string('nav_courses', 'local_clientportal'),
        html_writer::div(html_writer::table($table), 'cport-table-wrap')
    );
    manager::log($scope->id(), 'learner_viewed', $userid);
    echo ui::footer();
    exit;
}

// One course: activities. A course this client did not enrol the learner in stays private.
if (!$scope->owns($userid, $courseid)) {
    echo html_writer::div(
        html_writer::tag('h3', get_string('privatecourse', 'local_clientportal'), ['class' => 'h5']) .
        html_writer::tag('p', get_string('privatecourse_desc', 'local_clientportal')) .
        html_writer::link(
            $scope->url('/local/clientportal/learner.php', ['id' => $userid]),
            get_string('allcoursesfor', 'local_clientportal', fullname($learner)),
            ['class' => 'btn btn-outline-secondary']
        ),
        'cport-card cport-pad cport-private'
    );
    echo ui::footer();
    exit;
}
$row = null;
foreach ($rows as $r) {
    if ((int)$r->courseid === $courseid) {
        $row = $r;
    }
}
$sections = $report->activities($userid, $courseid);

$summary = html_writer::start_div('cport-stats');
$summary .= ui::stat(
    get_string('status', 'local_clientportal'),
    get_string('status_' . $row->status, 'local_clientportal'),
    '',
    $row->status === report::COMPLETED ? 'good' : ($row->status === report::INPROGRESS ? 'info' : 'warn')
);
$summary .= ui::stat(
    get_string('progress', 'local_clientportal'),
    $row->percent === null ? '–' : $row->percent . '%',
    get_string('activitiescount', 'local_clientportal', ['done' => $row->done, 'total' => $row->total])
);
$summary .= ui::stat(get_string('grade', 'local_clientportal'), $row->grade !== '' ? $row->grade : '–');
$summary .= ui::stat(
    get_string('completeddate', 'local_clientportal'),
    $row->completed ? userdate($row->completed, get_string('strftimedate', 'langconfig')) : '–'
);
$summary .= html_writer::end_div();
echo html_writer::tag('p', html_writer::link(
    $scope->url('/local/clientportal/learner.php', ['id' => $userid]),
    get_string('allcoursesfor', 'local_clientportal', fullname($learner))
), ['class' => 'cport-subtitle']);
echo html_writer::tag('h3', s($row->coursename), ['class' => 'h5 mb-3']);
echo $summary;
if (!empty($certs[$courseid])) {
    echo ui::card(
        get_string('certificates', 'local_clientportal'),
        html_writer::div(local_clientportal_cert_links($certs[$courseid], $scope, $userid), 'cport-pad')
    );
}

if (!$sections) {
    echo ui::card(get_string('activities', 'local_clientportal'), ui::empty(get_string('noactivities', 'local_clientportal')));
    echo ui::footer();
    exit;
}
$table = new html_table();
$table->attributes['class'] = 'table cport-table';
$table->head = [get_string('activity', 'local_clientportal'), get_string('status', 'local_clientportal'),
    get_string('completedat', 'local_clientportal'), get_string('grade', 'local_clientportal')];
foreach ($sections as $section) {
    $cell = new html_table_cell(s($section->name));
    $cell->colspan = 4;
    $cell->attributes['class'] = 'cport-section-title';
    $table->data[] = new html_table_row([$cell]);
    foreach ($section->activities as $a) {
        $name = html_writer::empty_tag('img', ['src' => $a->icon, 'alt' => '', 'class' => 'cport-icon']) . s($a->name) .
            html_writer::span(' · ' . s($a->modname), 'cport-muted');
        if (!$a->tracked) {
            $state = html_writer::span(get_string('notracked', 'local_clientportal'), 'text-muted small');
        } else if ($a->state === COMPLETION_COMPLETE || $a->state === COMPLETION_COMPLETE_PASS) {
            $state = html_writer::span(get_string(
                $a->state === COMPLETION_COMPLETE_PASS ? 'act_passed' : 'act_done',
                'local_clientportal'
            ), 'cport-pill cport-pill-completed');
        } else if ($a->state === COMPLETION_COMPLETE_FAIL || $a->state === COMPLETION_COMPLETE_FAIL_HIDDEN) {
            $state = html_writer::span(get_string('act_failed', 'local_clientportal'), 'cport-pill cport-pill-ready');
        } else if (!$a->available) {
            $state = html_writer::span(get_string('act_locked', 'local_clientportal'), 'cport-pill cport-pill-notstarted');
        } else {
            $state = html_writer::span(get_string('act_todo', 'local_clientportal'), 'cport-pill cport-pill-notstarted');
        }
        $table->data[] = [$name, $state, ui::date($a->time, true),
            $a->grade !== '' ? s($a->grade) : html_writer::span('–', 'text-muted')];
    }
}
echo ui::card(get_string('activities', 'local_clientportal'), html_writer::div(html_writer::table($table), 'cport-table-wrap'));
manager::log($scope->id(), 'learner_course_viewed', $userid, (string)$courseid);
echo ui::footer();
