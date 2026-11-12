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
 * Client Portal - progress report (learners by course) with CSV and Excel export.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\manager;
use local_clientportal\report;
use local_clientportal\scope;
use local_clientportal\ui;

require(__DIR__ . '/../../config.php');

require_login(null, false);
// pipeline-ignore: require_capability — access comes from client membership, checked by scope::require_portal().

$groupid = optional_param('group', 0, PARAM_INT);
$courseid = optional_param('course', 0, PARAM_INT);
$status = optional_param('status', '', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);
$params = array_filter(['group' => $groupid, 'course' => $courseid, 'status' => $status]);
$scope = scope::require_portal(new moodle_url('/local/clientportal/report.php', $params));
if ($groupid) {
    manager::get_group($scope->id(), $groupid);
}
$report = new report($scope);
$courses = $report->courses();
if ($courseid && !isset($courses[$courseid])) {
    throw new moodle_exception('error_notowned', 'local_clientportal');
}
if (!in_array($status, ['', report::COMPLETED, report::INPROGRESS, report::NOTSTARTED], true)) {
    $status = '';
}

// Export.
if ($download !== '') {
    $formats = ['csv' => 'csv', 'xlsx' => 'excel'];
    if (!isset($formats[$download])) {
        throw new moodle_exception('invalidparameter', 'debug');
    }
    [$columns, $rows] = $report->export($groupid ?: null, $courseid ?: null);
    if ($status !== '') {
        $label = get_string('status_' . $status, 'local_clientportal');
        $rows = array_values(array_filter($rows, fn($r) => $r['status'] === $label));
    }
    manager::log($scope->id(), 'report_exported', null, $download . ' (' . count($rows) . ')');
    $filename = clean_filename(format_string($scope->client->name) . ' progress ' . userdate(time(), '%Y-%m-%d'));
    \core\dataformat::download_data($filename, $formats[$download], $columns, new ArrayIterator($rows));
    exit;
}

$rows = $report->enrolments(null, $groupid ?: null, $courseid ?: null);
if ($status !== '') {
    $rows = array_filter($rows, fn($r) => $r->status === $status);
}
$report->add_names($rows);

$baseurl = $scope->url('/local/clientportal/report.php', $params);
$exports = html_writer::link(
    new moodle_url($baseurl, ['download' => 'csv']),
    get_string('exportcsv', 'local_clientportal'),
    ['class' => 'btn btn-outline-secondary']
) . html_writer::link(
    new moodle_url($baseurl, ['download' => 'xlsx']),
    get_string('exportxlsx', 'local_clientportal'),
    ['class' => 'btn btn-outline-secondary']
);
    echo ui::header($scope, 'reports', get_string('nav_reports', 'local_clientportal'), $exports);

    $statuses = ['' => get_string('allstatuses', 'local_clientportal'),
    report::COMPLETED => get_string('status_completed', 'local_clientportal'),
    report::INPROGRESS => get_string('status_inprogress', 'local_clientportal'),
    report::NOTSTARTED => get_string('status_notstarted', 'local_clientportal')];
    $filters = ui::filters($scope, $baseurl, $groupid, [
    'course' => [[0 => get_string('allcourses', 'local_clientportal')] + $courses, $courseid, get_string('course')],
    'status' => [$statuses, $status, get_string('status', 'local_clientportal')],
    ]);
    $body = html_writer::div(
        $filters . html_writer::span(get_string('rowcount', 'local_clientportal', count($rows)), 'cport-muted'),
        'cport-toolbar'
    );

    if (!$rows) {
        echo ui::card('', $body . ui::empty(get_string('noresults', 'local_clientportal')));
        echo ui::footer();
        exit;
    }

    if ($courseid || count($courses) === 1 || $status !== '') {
        // Detailed list: one row per learner and course.
        usort($rows, fn($a, $b) => [$a->fullname, $a->coursename] <=> [$b->fullname, $b->coursename]);
        $table = new html_table();
        $table->attributes['class'] = 'table cport-table';
        $table->head = [get_string('learner', 'local_clientportal'), get_string('course'),
            get_string('status', 'local_clientportal'),
        get_string('progress', 'local_clientportal'), get_string('enrolled', 'local_clientportal'),
        get_string('completeddate', 'local_clientportal'), get_string('grade', 'local_clientportal'),
        get_string('lastaccess', 'local_clientportal')];
        foreach ($rows as $r) {
            $table->data[] = [
            html_writer::link(
                $scope->url('/local/clientportal/learner.php', ['id' => $r->userid, 'course' => $r->courseid]),
                s($r->fullname),
                ['class' => 'cport-name']
            ),
                s($r->coursename),
                ui::status($r->status),
                ui::progress($r->percent),
                ui::date($r->enrolled),
                ui::date($r->completed),
                $r->grade !== '' ? s($r->grade) : html_writer::span('–', 'text-muted'),
                ui::date($r->lastaccess),
            ];
        }
    } else {
        // Matrix: learners down, courses across.
        $bylearner = [];
        foreach ($rows as $r) {
            $bylearner[$r->userid]['name'] = $r->fullname;
            $bylearner[$r->userid]['courses'][$r->courseid] = $r;
        }
        uasort($bylearner, fn($a, $b) => strcmp($a['name'], $b['name']));
        $table = new html_table();
        $table->attributes['class'] = 'table cport-table cport-matrix';
        $table->head = array_merge([get_string('learner', 'local_clientportal')], array_map('s', array_values($courses)));
        foreach ($bylearner as $userid => $l) {
            $line = [html_writer::link(
                $scope->url('/local/clientportal/learner.php', ['id' => $userid]),
                s($l['name']),
                ['class' => 'cport-name']
            )];
            foreach (array_keys($courses) as $cid) {
                $r = $l['courses'][$cid] ?? null;
                if (!$r) {
                    $line[] = html_writer::span(get_string('notenrolled', 'local_clientportal'), 'text-muted small');
                    continue;
                }
                $cell = html_writer::link(
                    $scope->url('/local/clientportal/learner.php', ['id' => $userid, 'course' => $cid]),
                    ui::status($r->status)
                );
                if ($r->completed) {
                    $cell .= html_writer::span(
                        userdate($r->completed, get_string('strftimedate', 'langconfig')),
                        'cport-cell-date'
                    );
                } else if ($r->percent !== null) {
                    $cell .= html_writer::span($r->percent . '%', 'cport-cell-date');
                }
                $line[] = $cell;
            }
            $table->data[] = $line;
        }
    }
    $body .= html_writer::div(html_writer::table($table), 'cport-table-wrap');
    echo ui::card('', $body);
    echo ui::footer();
