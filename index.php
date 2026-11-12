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
 * Client Portal - dashboard.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\report;
use local_clientportal\scope;
use local_clientportal\ui;

require(__DIR__ . '/../../config.php');

require_login(null, false);
// Access comes from client membership, checked by scope::require_portal(), so no require_capability() here.

$groupid = optional_param('group', 0, PARAM_INT);
$scope = scope::require_portal(new moodle_url('/local/clientportal/index.php', $groupid ? ['group' => $groupid] : []));
if ($groupid) {
    local_clientportal\manager::get_group($scope->id(), $groupid);
}
$report = new report($scope);
$rows = $report->enrolments(null, $groupid ?: null);

$sum = (object)['enrolments' => count($rows), 'completed' => 0, 'inprogress' => 0, 'notstarted' => 0, 'recent' => 0];
$since = time() - 30 * DAYSECS;
$bycourse = [];
foreach ($rows as $r) {
    $sum->{$r->status}++;
    if ($r->completed && $r->completed >= $since) {
        $sum->recent++;
    }
    $c = $bycourse[$r->courseid] ??= (object)['name' => $r->coursename, 'courseid' => $r->courseid, 'enrolled' => 0,
        'completed' => 0, 'inprogress' => 0, 'notstarted' => 0];
    $c->enrolled++;
    $c->{$r->status}++;
}
$learners = $report->count_learners($groupid ?: null);
$rate = $sum->enrolments ? (int)round($sum->completed * 100 / $sum->enrolments) : 0;

$filters = ui::filters($scope, $scope->url('/local/clientportal/index.php'), $groupid);
echo ui::header($scope, 'dashboard', get_string('nav_dashboard', 'local_clientportal'), $filters);

echo html_writer::start_div('cport-stats');
echo ui::stat(get_string('stat_learners', 'local_clientportal'), $learners);
echo ui::stat(get_string('stat_enrolments', 'local_clientportal'), $sum->enrolments);
echo ui::stat(
    get_string('stat_completed', 'local_clientportal'),
    $sum->completed,
    get_string('stat_rate', 'local_clientportal', $rate),
    'good'
);
echo ui::stat(get_string('status_inprogress', 'local_clientportal'), $sum->inprogress, '', 'info');
echo ui::stat(get_string('status_notstarted', 'local_clientportal'), $sum->notstarted, '', $sum->notstarted ? 'warn' : 'neutral');
echo ui::stat(get_string('stat_recent', 'local_clientportal'), $sum->recent, get_string('stat_recent_sub', 'local_clientportal'));
echo html_writer::end_div();

if (!$rows) {
    echo ui::card('', ui::empty(get_string('noenrolments', 'local_clientportal')));
    echo ui::footer();
    exit;
}

// Courses.
$table = new html_table();
$table->attributes['class'] = 'table cport-table';
$table->head = [get_string('course'), get_string('stat_enrolments', 'local_clientportal'),
    get_string('status_completed', 'local_clientportal'), get_string('status_inprogress', 'local_clientportal'),
    get_string('status_notstarted', 'local_clientportal'), get_string('completionrate', 'local_clientportal')];
uasort($bycourse, fn($a, $b) => strcmp($a->name, $b->name));
foreach ($bycourse as $c) {
    $url = $scope->url('/local/clientportal/report.php', ['course' => $c->courseid] + ($groupid ? ['group' => $groupid] : []));
    $table->data[] = [
        html_writer::link($url, s($c->name), ['class' => 'cport-name']),
        $c->enrolled, $c->completed, $c->inprogress, $c->notstarted,
        ui::progress((int)round($c->completed * 100 / max(1, $c->enrolled))),
    ];
}
echo ui::card(get_string('courses', 'local_clientportal'), html_writer::div(html_writer::table($table), 'cport-table-wrap'));

echo html_writer::start_div('cport-grid');

// Recent completions.
$recent = array_filter($rows, fn($r) => !empty($r->completed));
usort($recent, fn($a, $b) => $b->completed <=> $a->completed);
$recent = array_slice($recent, 0, 8);
$report->add_names($recent);
if ($recent) {
    $t = new html_table();
    $t->attributes['class'] = 'table cport-table';
    $t->head = [get_string('learner', 'local_clientportal'), get_string('course'),
        get_string('completeddate', 'local_clientportal')];
    foreach ($recent as $r) {
        $t->data[] = [
            html_writer::link(
                $scope->url('/local/clientportal/learner.php', ['id' => $r->userid]),
                s($r->fullname),
                ['class' => 'cport-name']
            ),
            s($r->coursename),
            ui::date($r->completed),
        ];
    }
    $body = html_writer::div(html_writer::table($t), 'cport-table-wrap');
} else {
    $body = ui::empty(get_string('norecent', 'local_clientportal'));
}
echo ui::card(get_string('recentcompletions', 'local_clientportal'), $body);

// Groups.
if (!$groupid) {
    $groups = $report->group_summary();
    if ($groups) {
        $t = new html_table();
        $t->attributes['class'] = 'table cport-table';
        $t->head = [get_string('group', 'local_clientportal'), get_string('stat_learners', 'local_clientportal'),
            get_string('stat_completed', 'local_clientportal'), get_string('completionrate', 'local_clientportal')];
        foreach ($groups as $g) {
            $t->data[] = [
                html_writer::link(
                    $scope->url('/local/clientportal/index.php', ['group' => $g->id]),
                    s($g->name),
                    ['class' => 'cport-name']
                ),
                $g->learners, $g->completed . ' / ' . $g->enrolments, ui::progress($g->rate),
            ];
        }
        $body = html_writer::div(html_writer::table($t), 'cport-table-wrap');
    } else {
        $body = html_writer::div(
            get_string('nogroupsyet', 'local_clientportal') . ' ' .
            html_writer::link($scope->url('/local/clientportal/groups.php'), get_string('creategroup', 'local_clientportal')),
            'cport-empty'
        );
    }
    echo ui::card(get_string('nav_groups', 'local_clientportal'), $body);
}
echo html_writer::end_div();

echo ui::footer();
