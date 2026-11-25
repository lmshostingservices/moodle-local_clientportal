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
 * Client Portal - shared courses: keep clients apart inside courses that several clients' learners take.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\manager;
use local_clientportal\shared;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_clientportal_shared');
$context = context_system::instance();
require_capability('local/clientportal:manageclients', $context);
$pageurl = new moodle_url('/local/clientportal/admin/shared.php');

$action = optional_param('action', '', PARAM_ALPHA);
$courseid = optional_param('course', 0, PARAM_INT);
$str = fn(string $id, $a = null) => get_string($id, 'local_clientportal', $a);

// Confirmed actions.
if ($action && $courseid && optional_param('confirm', 0, PARAM_BOOL) && confirm_sesskey()) {
    if ($action === 'setup') {
        shared::setup(
            $courseid,
            optional_param('groupmode', SEPARATEGROUPS, PARAM_INT),
            optional_param('applyactivities', 0, PARAM_BOOL),
            optional_param('locked', 0, PARAM_BOOL)
        );
        redirect($pageurl, $str('shared_done'), null, \core\output\notification::NOTIFY_SUCCESS);
    } else if ($action === 'undo') {
        shared::undo($courseid, optional_param('removegroups', 0, PARAM_BOOL));
        redirect($pageurl, $str('shared_undone'), null, \core\output\notification::NOTIFY_SUCCESS);
    } else if ($action === 'sync') {
        $n = shared::sync_course($courseid);
        redirect($pageurl, $str('shared_synced', $n), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

$clientnames = [];
foreach (manager::get_clients() as $c) {
    $clientnames[(int)$c->id] = format_string($c->name);
}
$clientlist = function (array $clients) use ($clientnames): string {
    $out = [];
    foreach ($clients as $clientid => $count) {
        $out[] = s($clientnames[$clientid] ?? '?') . ' (' . (int)$count . ')';
    }
    return implode(', ', $out);
};

// Set-up dialog: show exactly what will change before confirming.
if ($action === 'setup' && $courseid) {
    $defaults = shared::defaults();
    $groupmode = optional_param('groupmode', $defaults['groupmode'], PARAM_INT);
    $preview = shared::preview($courseid, $groupmode);
    $existing = shared::get($courseid);
    echo $OUTPUT->header();
    echo $OUTPUT->heading($str('shared_setuptitle', format_string($preview->course->fullname)));
    echo html_writer::tag('p', $str('shared_setupintro'));
    $items = [
        $str('shared_willgroups', $clientlist($preview->clients)),
        $str('shared_willcourse', [
            'mode' => get_string($groupmode === VISIBLEGROUPS ? 'groupsvisible' : 'groupsseparate'),
            'now' => get_string(match ((int)$preview->course->groupmode) {
                SEPARATEGROUPS => 'groupsseparate',
                VISIBLEGROUPS => 'groupsvisible',
                default => 'groupsnone',
            }),
        ]),
        $str('shared_willactivities', ['total' => $preview->activities, 'change' => count($preview->different)]),
    ];
    echo html_writer::alist($items);
    if ($preview->different) {
        $names = array_map(fn($cm) => s($cm->get_formatted_name()), array_slice($preview->different, 0, 20));
        echo html_writer::div($str('shared_activitieslist') . ' ' . implode(', ', $names) .
            (count($preview->different) > 20 ? ' …' : ''), 'text-muted small mb-3');
    }
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
    foreach (['sesskey' => sesskey(), 'action' => 'setup', 'course' => $courseid, 'confirm' => 1] as $name => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }
    echo html_writer::div(
        html_writer::label($str('sharedgroupmode'), 'cport-groupmode') .
        html_writer::select([SEPARATEGROUPS => get_string('groupsseparate'), VISIBLEGROUPS => get_string('groupsvisible')],
            'groupmode', $groupmode, false, ['id' => 'cport-groupmode', 'class' => 'custom-select form-select ms-2']),
        'mb-2'
    );
    $apply = $existing ? (bool)$existing->applyactivities : $defaults['applyactivities'];
    $locked = $existing ? (bool)$existing->locked : $defaults['locked'];
    echo html_writer::div(html_writer::checkbox('applyactivities', 1, $apply, ' ' . $str('sharedapplyactivities')), 'mb-1');
    echo html_writer::div($str('sharedapplyactivities_desc'), 'text-muted small mb-2');
    echo html_writer::div(html_writer::checkbox('locked', 1, $locked, ' ' . $str('sharedlock')), 'mb-1');
    echo html_writer::div($str('sharedlock_desc'), 'text-muted small mb-3');
    echo html_writer::div($str('shared_undonote'), 'alert alert-info');
    echo html_writer::tag('button', $str('shared_keepapart'), ['type' => 'submit', 'class' => 'btn btn-primary']) . ' ';
    echo html_writer::link($pageurl, get_string('cancel'), ['class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
    exit;
}

// Undo dialog.
if ($action === 'undo' && $courseid && ($record = shared::get($courseid))) {
    $course = get_course($courseid);
    echo $OUTPUT->header();
    echo $OUTPUT->heading($str('shared_undotitle', format_string($course->fullname)));
    echo html_writer::tag('p', $str('shared_undointro'));
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
    foreach (['sesskey' => sesskey(), 'action' => 'undo', 'course' => $courseid, 'confirm' => 1] as $name => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }
    echo html_writer::div(html_writer::checkbox('removegroups', 1, false, ' ' . $str('shared_removegroups')), 'mb-3');
    echo html_writer::tag('button', $str('shared_undo'), ['type' => 'submit', 'class' => 'btn btn-danger']) . ' ';
    echo html_writer::link($pageurl, get_string('cancel'), ['class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
    exit;
}

// Overview.
$courses = shared::overview();
echo $OUTPUT->header();
echo $OUTPUT->heading($str('sharedcourses'));
echo html_writer::tag('p', $str('shared_intro'));
$mode = shared::mode();
echo $OUTPUT->notification($str('shared_modenow', $str('sharedmode_' . $mode)), 'info');

if (!$courses) {
    echo $OUTPUT->notification($str('shared_none'), 'success');
    echo $OUTPUT->footer();
    exit;
}

$badges = [
    'notsetup' => 'bg-warning badge-warning text-dark',
    'attention' => 'bg-danger badge-danger',
    'apart' => 'bg-success badge-success',
    'locked' => 'bg-success badge-success',
];
$table = new html_table();
$table->attributes['class'] = 'generaltable mt-3';
$table->head = [get_string('course'), $str('clients'), get_string('groupmode', 'group'), get_string('status'), ''];
foreach ($courses as $c) {
    $link = html_writer::link(new moodle_url('/course/view.php', ['id' => $c->id]), format_string($c->fullname));
    $groupsurl = new moodle_url('/group/index.php', ['id' => $c->id]);
    $status = html_writer::span($str('sharedstatus_' . $c->status), 'badge ' . $badges[$c->status]);
    foreach ($c->issues as $issue) {
        $status .= html_writer::div(s($issue), 'small text-danger mt-1');
    }
    $buttons = [];
    if (!$c->setup) {
        $buttons[] = html_writer::link(new moodle_url($pageurl, ['action' => 'setup', 'course' => $c->id]),
            $str('shared_keepapart'), ['class' => 'btn btn-primary btn-sm']);
    } else {
        $buttons[] = html_writer::link(new moodle_url($pageurl, ['action' => 'setup', 'course' => $c->id]),
            get_string('edit'), ['class' => 'btn btn-outline-secondary btn-sm']);
        if (isset($c->issues['sync'])) {
            $buttons[] = html_writer::link(new moodle_url($pageurl, ['action' => 'sync', 'course' => $c->id, 'confirm' => 1,
                'sesskey' => sesskey()]), $str('shared_fixnow'), ['class' => 'btn btn-primary btn-sm']);
        }
        $buttons[] = html_writer::link($groupsurl, get_string('groups'), ['class' => 'btn btn-outline-secondary btn-sm']);
        $buttons[] = html_writer::link(new moodle_url($pageurl, ['action' => 'undo', 'course' => $c->id]),
            $str('shared_undo'), ['class' => 'btn btn-outline-danger btn-sm']);
    }
    $table->data[] = [
        $link,
        $clientlist($c->clients),
        get_string(match ((int)$c->groupmode) {
            SEPARATEGROUPS => 'groupsseparate',
            VISIBLEGROUPS => 'groupsvisible',
            default => 'groupsnone',
        }) . ((int)$c->groupmodeforce ? ' (' . $str('sharedforced') . ')' : ''),
        $status,
        implode(' ', $buttons),
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
