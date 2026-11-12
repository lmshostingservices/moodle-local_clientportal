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
 * Client Portal - pathways: who is on each onboarding pathway, how far they have got, and the sign-offs waiting.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\integration\enrolment;
use local_clientportal\integration\pathways;
use local_clientportal\manager;
use local_clientportal\report;
use local_clientportal\scope;
use local_clientportal\ui;

require(__DIR__ . '/../../config.php');

require_login(null, false);
// Access comes from client membership, checked by scope::require_portal(), so no require_capability() here.

$pathwayid = optional_param('pathway', 0, PARAM_INT);
$groupid = optional_param('group', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$params = array_filter(['pathway' => $pathwayid, 'group' => $groupid]);
$scope = scope::require_portal(new moodle_url('/local/clientportal/pathways.php', $params));
if (!pathways::available()) {
    redirect($scope->url('/local/clientportal/index.php'));
}
if ($groupid) {
    manager::get_group($scope->id(), $groupid);
}
$baseurl = $scope->url('/local/clientportal/pathways.php', $params);
$all = pathways::all($scope);
if ($pathwayid && !isset($all[$pathwayid])) {
    redirect(
        $scope->url('/local/clientportal/pathways.php'),
        get_string('error_badpathway', 'local_clientportal'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}
$canassign = $scope->is_manager();
$cansignoff = $scope->is_manager() && pathways::signoff_allowed();

// Actions (managers).
if ($action === 'signoff' && $cansignoff && confirm_sesskey()) {
    $assignmentid = required_param('assignment', PARAM_INT);
    $taskid = required_param('task', PARAM_INT);
    $progress = pathways::sign_off($scope, $assignmentid, $taskid);
    $returnto = optional_param('returnto', 0, PARAM_INT);
    redirect(
        $returnto ? $scope->url('/local/clientportal/learner.php', ['id' => $returnto]) : $baseurl,
        get_string('signedoff', 'local_clientportal', $progress['percent']),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}
if ($action === 'assign' && $canassign && $pathwayid && confirm_sesskey()) {
    $userids = optional_param_array('users', [], PARAM_INT);
    $target = optional_param('target', '', PARAM_TEXT);
    $targetdate = $target !== '' ? (strtotime($target . ' 23:59') ?: null) : null;
    $count = 0;
    $blocked = [];
    foreach ($userids as $uid) {
        if (enrolment::allowed()) {
            $done = enrolment::enrol_courses($scope, (int)$uid, pathways::course_ids($all[$pathwayid]));
            $blocked = array_merge($blocked, $done['blocked']);
        }
        pathways::allocate($scope, $pathwayid, (int)$uid, $targetdate);
        $count++;
    }
    $msg = get_string('pathwayassigned', 'local_clientportal', $count);
    if ($blocked) {
        $msg .= ' ' . get_string('onboard_blocked', 'local_clientportal', implode(', ', array_unique($blocked)));
    }
    redirect($baseurl, $msg, null, $blocked ? \core\output\notification::NOTIFY_WARNING
        : \core\output\notification::NOTIFY_SUCCESS);
}

$report = new report($scope);
$learners = $report->learners($groupid ?: null, '', 'name', 0, 0);
$ids = array_map(fn($u) => (int)$u->id, $learners);
$assignments = pathways::assignments($scope, $ids, $pathwayid);

/**
 * Sign-off buttons (or a waiting list) for one assignment.
 *
 * @param stdClass $a
 * @param bool $cansignoff
 * @param moodle_url $baseurl
 * @return string
 */
function local_clientportal_signoff_cell(stdClass $a, bool $cansignoff, moodle_url $baseurl): string {
    $out = '';
    foreach ($a->signoffs as $task) {
        if ($cansignoff) {
            $out .= html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false),
                'class' => 'cport-signoff']);
            $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'signoff']);
            $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'assignment', 'value' => $a->id]);
            $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'task', 'value' => $task->id]);
            $out .= html_writer::tag(
                'button',
                get_string('signoff', 'local_clientportal', format_string($task->name)),
                ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-primary']
            );
            $out .= html_writer::end_tag('form');
        } else {
            $out .= html_writer::div(s(format_string($task->name)), 'cport-muted');
        }
    }
    return $out ?: html_writer::span('–', 'text-muted');
}

$names = [];
foreach ($learners as $u) {
    $names[(int)$u->id] = $u;
}

// Summary.
$sum = ['assigned' => 0, 'done' => 0, 'overdue' => 0, 'signoffs' => 0, 'percent' => 0];
foreach ($assignments as $a) {
    $sum['assigned']++;
    $sum['done'] += $a->status === 'completed' ? 1 : 0;
    $sum['overdue'] += $a->overdue ? 1 : 0;
    $sum['signoffs'] += count($a->signoffs);
    $sum['percent'] += $a->percent;
}
$avg = $sum['assigned'] ? (int)round($sum['percent'] / $sum['assigned']) : 0;

$title = $pathwayid ? format_string($all[$pathwayid]->name) : get_string('nav_pathways', 'local_clientportal');
$actions = ui::filters($scope, $baseurl, $groupid);
if ($pathwayid) {
    $actions .= html_writer::link(
        $scope->url('/local/clientportal/pathways.php', array_filter(['group' => $groupid])),
        get_string('allpathways', 'local_clientportal'),
        ['class' => 'btn btn-outline-secondary']
    );
}
echo ui::header($scope, 'pathways', $title, $actions);

echo html_writer::start_div('cport-stats');
echo ui::stat(get_string('stat_onpathway', 'local_clientportal'), $sum['assigned'] - $sum['done'], '', 'accent');
echo ui::stat(get_string('stat_pathwaysdone', 'local_clientportal'), $sum['done'], '', 'good');
echo ui::stat(get_string('stat_avgprogress', 'local_clientportal'), $avg . '%', '', 'info');
echo ui::stat(get_string('stat_overduepathway', 'local_clientportal'), $sum['overdue'], '', $sum['overdue'] ? 'warn' : 'neutral');
echo ui::stat(
    get_string('stat_signoffs', 'local_clientportal'),
    $sum['signoffs'],
    $cansignoff ? get_string('stat_signoffs_sub', 'local_clientportal') : '',
    $sum['signoffs'] ? 'warn' : 'neutral'
);
echo html_writer::end_div();

if (!$pathwayid) {
    // Pathway cards.
    if (!$all) {
        echo ui::card('', ui::empty(get_string('nopathways', 'local_clientportal')));
        echo ui::footer();
        exit;
    }
    $cards = '';
    foreach ($all as $p) {
        $mine = array_filter($assignments, fn($a) => (int)$a->pathwayid === (int)$p->id);
        $active = count(array_filter($mine, fn($a) => $a->status === 'active'));
        $done = count($mine) - $active;
        $late = count(array_filter($mine, fn($a) => $a->overdue));
        $tasks = array_sum(array_map(fn($s) => count($s->tasks), $p->stages));
        $avgp = $mine ? (int)round(array_sum(array_map(fn($a) => $a->percent, $mine)) / count($mine)) : 0;
        $meta = get_string('pathwaymeta', 'local_clientportal', ['stages' => count($p->stages), 'tasks' => $tasks]);
        $badge = (int)$p->clientid === 0 ? html_writer::span(get_string('sharedpathway', 'local_clientportal'), 'cport-tag')
            : html_writer::span(get_string('ownpathway', 'local_clientportal'), 'cport-tag cport-tag-own');
        $minis = [['pw_working', $active, ''], ['pw_finished', $done, 'good'], ['overdue', $late, $late ? 'warn' : '']];
        $mini = '';
        foreach (
            $minis as [$key, $value, $tone]
        ) {
            $mini .= html_writer::div(
                html_writer::div((string)$value, 'cport-mini-value') .
                html_writer::div(get_string($key, 'local_clientportal'), 'cport-mini-label'),
                'cport-mini' . ($tone ? ' cport-mini-' . $tone : '')
            );
        }
        $desc = $p->description ? shorten_text(html_to_text(format_text($p->description), 0, false), 120) : '';
        $url = $scope->url('/local/clientportal/pathways.php', ['pathway' => $p->id]);
        $cards .= html_writer::div(
            html_writer::div(
                html_writer::tag('h3', html_writer::link($url, s(format_string($p->name))), ['class' => 'cport-pwcard-title']) .
                $badge,
                'cport-pwcard-head'
            ) .
            html_writer::div(s($meta), 'cport-muted') .
            ($desc !== '' ? html_writer::tag('p', s($desc), ['class' => 'cport-pwcard-desc']) : '') .
            html_writer::div($mini, 'cport-minis') .
            html_writer::div(get_string('stat_avgprogress', 'local_clientportal'), 'cport-mini-label') .
            ui::progress($avgp) .
            html_writer::div(html_writer::link(
                $url,
                get_string('openpathway', 'local_clientportal'),
                ['class' => 'btn btn-sm btn-primary']
            ), 'cport-pwcard-foot'),
            'cport-card cport-pwcard cport-pwcard-' . s($p->colour)
        );
    }
    echo html_writer::div($cards, 'cport-pwgrid');
}

if ($pathwayid) {
    // The pathway's stages.
    $stages = '';
    $n = 0;
    foreach ($all[$pathwayid]->stages as $stage) {
        $n++;
        $items = '';
        foreach ($stage->tasks as $task) {
            $items .= html_writer::tag('li', s(format_string($task->name)) . ' ' .
                html_writer::span(get_string('tasktype_' . $task->tasktype, 'local_clientportal'), 'cport-tag'));
        }
        $stages .= html_writer::div(
            html_writer::div(get_string('stagen', 'local_clientportal', $n), 'cport-stage-num') .
            html_writer::tag('h4', s(format_string($stage->name)), ['class' => 'h6']) .
            html_writer::tag('ul', $items, ['class' => 'cport-stage-tasks']),
            'cport-stage'
        );
    }
    $stages = html_writer::div(html_writer::div($stages, 'cport-stages'), 'cport-pad');
    echo ui::card(get_string('stages', 'local_clientportal'), $stages);
}

// People on the pathway(s).
if ($assignments) {
    $table = new html_table();
    $table->attributes['class'] = 'table cport-table';
    $table->head = [get_string('learner', 'local_clientportal')];
    if (!$pathwayid) {
        $table->head[] = get_string('pathway', 'local_clientportal');
    }
    $table->head = array_merge($table->head, [get_string('currentstage', 'local_clientportal'),
        get_string('progress', 'local_clientportal'), get_string('targetdate', 'local_clientportal'),
        get_string('signoffswaiting', 'local_clientportal')]);
    usort($assignments, fn($a, $b) => [$b->overdue, count($b->signoffs), $a->percent] <=>
        [$a->overdue, count($a->signoffs), $b->percent]);
    foreach ($assignments as $a) {
        $u = $names[(int)$a->userid];
        $row = [html_writer::link(
            $scope->url('/local/clientportal/learner.php', ['id' => $u->id]),
            fullname($u),
            ['class' => 'cport-name']
        )];
        if (!$pathwayid) {
            $row[] = s(format_string($a->pathway->name));
        }
        $row[] = $a->status === 'completed' ? ui::status('completed')
            : s($a->stage ? format_string($a->stage->name) : '–');
        $row[] = ui::progress($a->percent);
        $row[] = ui::date($a->targetdate ? (int)$a->targetdate : null) .
            ($a->overdue ? ' ' . html_writer::span(get_string('overdue', 'local_clientportal'), 'cport-pill cport-pill-late') : '');
        $row[] = local_clientportal_signoff_cell($a, $cansignoff, $baseurl);
        $table->data[] = $row;
    }
    $body = html_writer::div(html_writer::table($table), 'cport-table-wrap');
    echo ui::card(get_string('peopleonpathway', 'local_clientportal'), $body);
} else if ($pathwayid) {
    $body = ui::empty(get_string('nooneonpathway', 'local_clientportal'));
    echo ui::card(get_string('peopleonpathway', 'local_clientportal'), $body);
}

// Put people on this pathway.
if ($pathwayid && $canassign) {
    $on = [];
    foreach ($assignments as $a) {
        if ($a->status === 'active') {
            $on[(int)$a->userid] = true;
        }
    }
    $options = [];
    foreach ($learners as $u) {
        if (!isset($on[(int)$u->id]) && !$u->suspended) {
            $options[$u->id] = fullname($u);
        }
    }
    if ($options) {
        $form = html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false), 'class' => 'cport-assign']);
        $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'assign']);
        $form .= html_writer::div(get_string('chooselearners', 'local_clientportal'), 'cport-field-label');
        $picks = '';
        foreach ($options as $id => $name) {
            $picks .= html_writer::tag('label', html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'users[]',
                'value' => $id]) . html_writer::span(s($name)), ['class' => 'cport-pick']);
        }
        $form .= html_writer::div($picks, 'cport-picklist');
        $form .= html_writer::div(
            html_writer::div(
                html_writer::label(
                    get_string('targetdate', 'local_clientportal'),
                    'cport-target',
                    true,
                    ['class' => 'cport-field-label']
                ) .
                html_writer::empty_tag('input', ['type' => 'date', 'name' => 'target', 'id' => 'cport-target',
                    'class' => 'form-control']),
                'cport-assign-date'
            ) .
            html_writer::tag(
                'button',
                get_string('assignpathway', 'local_clientportal'),
                ['type' => 'submit', 'class' => 'btn btn-primary']
            ),
            'cport-assign-row'
        );
        $form .= html_writer::tag('p', get_string(
            enrolment::allowed() ? 'assignpathway_enrol' : 'assignpathway_noenrol',
            'local_clientportal'
        ), ['class' => 'cport-muted mt-2 mb-0']);
        $form .= html_writer::end_tag('form');
        echo ui::card(get_string('assignpeople', 'local_clientportal'), html_writer::div($form, 'cport-pad'));
    }
}
echo ui::footer();
