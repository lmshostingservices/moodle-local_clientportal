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
 * Client Portal - required training: who is due, overdue or done, with reminders and an export.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\compliance\engine;
use local_clientportal\compliance\rules;
use local_clientportal\compliance\view;
use local_clientportal\manager;
use local_clientportal\scope;
use local_clientportal\ui;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

require_login(null, false);
// Access comes from client membership, checked by scope::require_portal(), so no require_capability() here.

$ruleid = optional_param('rule', 0, PARAM_INT);
$groupid = optional_param('group', 0, PARAM_INT);
$status = optional_param('status', '', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);
$params = array_filter(['rule' => $ruleid, 'group' => $groupid, 'status' => $status]);
$scope = scope::require_portal(new moodle_url('/local/clientportal/compliancedue.php', $params));
$pageurl = $scope->url('/local/clientportal/compliancedue.php', $params);
if ($groupid) {
    manager::get_group($scope->id(), $groupid);
}
if ($ruleid) {
    rules::get($scope, $ruleid);
}
if (!in_array($status, view::STATUSES, true)) {
    $status = '';
}

// Remind one learner now.
if (($remind = optional_param('remind', 0, PARAM_INT)) && $scope->is_manager() && confirm_sesskey()) {
    $ok = engine::remind_now($scope, $remind);
    redirect(
        $pageurl,
        get_string($ok ? 'due_reminded' : 'due_remindfailed', 'local_clientportal'),
        null,
        $ok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
    );
}

$rows = view::rows($scope, ['ruleid' => $ruleid, 'groupid' => $groupid, 'status' => $status]);

if ($download === 'csv') {
    $csv = new csv_export_writer();
    $csv->set_filename(clean_filename(format_string($scope->client->name) . '-required-training-' . date('Y-m-d')));
    $csv->add_data([get_string('learner', 'local_clientportal'), get_string('email'),
        get_string('rule_course', 'local_clientportal'), get_string('due_cycle', 'local_clientportal'),
        get_string('due_assigned', 'local_clientportal'), get_string('due_date', 'local_clientportal'),
        get_string('status', 'local_clientportal'), get_string('due_completed', 'local_clientportal'),
        get_string('grade'), get_string('due_next', 'local_clientportal')]);
    $fmt = fn($t) => $t ? userdate((int)$t, '%Y-%m-%d') : '';
    foreach ($rows as $r) {
        $csv->add_data([fullname($r), $r->email, format_string($r->coursename), $r->cycle, $fmt($r->timeassigned),
            $fmt($r->timedue), get_string('due_' . $r->state, 'local_clientportal'), $fmt($r->timecompleted),
            (string)$r->grade, $fmt($r->nextdue)]);
    }
    manager::log($scope->id(), 'due_downloaded');
    $csv->download_file();
    exit;
}

// Totals across everything shown (before the status filter).
$all = $status === '' ? $rows : view::rows($scope, ['ruleid' => $ruleid, 'groupid' => $groupid]);
$sum = array_fill_keys(view::STATUSES, 0);
foreach ($all as $r) {
    $sum[$r->state]++;
}
$total = count($all);
$ontrack = $total ? (int)round(($total - $sum['overdue']) * 100 / $total) : 0;

$actions = html_writer::link(
    $scope->url('/local/clientportal/compliancedue.php', $params + ['download' => 'csv']),
    get_string('exportcsv', 'local_clientportal'),
    ['class' => 'btn btn-outline-secondary']
);
if ($scope->is_manager()) {
    $actions .= ' ' . html_writer::link(
        $scope->url('/local/clientportal/compliancerules.php', ['action' => 'edit']),
        get_string('rule_add', 'local_clientportal'),
        ['class' => 'btn btn-primary']
    );
}
echo ui::header($scope, 'compliance', get_string('nav_compliance', 'local_clientportal'), $actions);
echo view::tabs($scope, 'due');

$rulelist = rules::all($scope);
if (!$rulelist) {
    echo ui::card('', ui::empty(get_string($scope->is_manager() ? 'due_norules' : 'due_norules_rep', 'local_clientportal')));
    echo ui::footer();
    exit;
}

echo html_writer::start_div('cport-stats');
echo ui::stat(
    get_string('due_stat_ontrack', 'local_clientportal'),
    $ontrack . '%',
    get_string('due_stat_ontrack_sub', 'local_clientportal', (object)['n' => $total - $sum['overdue'], 'of' => $total]),
    'good'
);
echo ui::stat(
    get_string('due_overdue', 'local_clientportal'),
    $sum['overdue'],
    get_string('due_stat_overdue_sub', 'local_clientportal'),
    $sum['overdue'] ? 'warn' : 'neutral'
);
echo ui::stat(
    get_string('due_duesoon', 'local_clientportal'),
    $sum['duesoon'],
    get_string('due_stat_soon_sub', 'local_clientportal', view::SOON),
    'neutral'
);
echo ui::stat(
    get_string('due_completed', 'local_clientportal'),
    $sum['completed'],
    get_string('due_stat_done_sub', 'local_clientportal'),
    'accent'
);
echo html_writer::end_div();

$ruleoptions = [0 => get_string('due_allcourses', 'local_clientportal')];
foreach ($rulelist as $r) {
    $ruleoptions[$r->id] = format_string($r->coursename) . ($r->enabled ? '' : ' (' .
        get_string('rule_off', 'local_clientportal') . ')');
}
$statusoptions = ['' => get_string('allstatuses', 'local_clientportal')];
foreach (view::STATUSES as $s) {
    $statusoptions[$s] = get_string('due_' . $s, 'local_clientportal');
}
echo ui::filters($scope, $pageurl, $groupid, [
    'rule' => [$ruleoptions, $ruleid, get_string('rule_course', 'local_clientportal')],
    'status' => [$statusoptions, $status, get_string('status', 'local_clientportal')],
]);

if (!$rows) {
    echo ui::card('', ui::empty(get_string('due_none', 'local_clientportal')));
    echo ui::footer();
    exit;
}
$table = new html_table();
$table->attributes['class'] = 'table cport-table';
$table->head = [
    get_string('learner', 'local_clientportal'),
    get_string('rule_course', 'local_clientportal'),
    get_string('due_date', 'local_clientportal'),
    get_string('status', 'local_clientportal'),
    get_string('due_completed', 'local_clientportal'),
    get_string('due_next', 'local_clientportal'),
    '',
];
foreach ($rows as $r) {
    $remind = '';
    if ($scope->is_manager() && $r->status === 'open') {
        $remind = html_writer::link(
            new moodle_url($pageurl, ['remind' => $r->id, 'sesskey' => sesskey()]),
            get_string('due_remind', 'local_clientportal'),
            ['class' => 'btn btn-sm btn-outline-secondary']
        );
    }
    $done = $r->timecompleted ? ui::date((int)$r->timecompleted) . ($r->grade !== null && $r->grade !== ''
        ? html_writer::div(s($r->grade), 'small text-muted') : '') : '–';
    $cycle = (int)$r->cycle > 1 ? html_writer::div(
        get_string('due_renewal', 'local_clientportal', (int)$r->cycle - 1),
        'small text-muted'
    ) : '';
    $table->data[] = [
        html_writer::link($scope->url('/local/clientportal/learner.php', ['id' => $r->userid]), fullname($r)),
        format_string($r->coursename) . $cycle,
        $r->state === 'completed' ? '–' : ui::date((int)$r->timedue),
        view::chip($r),
        $done,
        $r->nextdue ? ui::date((int)$r->nextdue) : '–',
        $remind,
    ];
}
echo ui::card('', html_writer::table($table));
echo ui::footer();
