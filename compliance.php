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
 * Client Portal - compliance: every learner against the competencies their position needs.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\integration\matrix;
use local_clientportal\manager;
use local_clientportal\report;
use local_clientportal\scope;
use local_clientportal\ui;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

require_login(null, false);
// Access comes from client membership, checked by scope::require_portal(), so no require_capability() here.

$groupid = optional_param('group', 0, PARAM_INT);
$deptid = optional_param('department', 0, PARAM_INT);
$posid = optional_param('position', 0, PARAM_INT);
$status = optional_param('status', '', PARAM_ALPHANUMEXT);
$download = optional_param('download', '', PARAM_ALPHA);
$search = optional_param('search', '', PARAM_TEXT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;
$params = array_filter(['group' => $groupid, 'department' => $deptid, 'position' => $posid, 'status' => $status,
    'search' => $search]);
$scope = scope::require_portal(new moodle_url('/local/clientportal/compliance.php', $params));
if (!matrix::available()) {
    redirect($scope->url('/local/clientportal/index.php'));
}
if ($groupid) {
    manager::get_group($scope->id(), $groupid);
}
if ($status !== '' && !in_array($status, array_merge(matrix::STATUSES, ['noncompliant']), true)) {
    $status = '';
}

$departments = matrix::departments($scope);
$positions = matrix::positions($scope);
$report = new report($scope);
$learners = $report->learners($groupid ?: null, $search, 'name', 0, 0);
$data = matrix::compliance($scope, array_map(fn($u) => (int)$u->id, $learners));
$people = [];
foreach ($learners as $u) {
    $c = $data[$u->id] ?? null;
    if (!$c || ($deptid && (int)$c['staff']->departmentid !== $deptid) || ($posid && (int)$c['staff']->positionid !== $posid)) {
        continue;
    }
    if ($status === 'noncompliant' && $c['stats']['compliance_rate'] >= 100) {
        continue;
    }
    if (
        $status !== '' && $status !== 'noncompliant'
            && !array_filter($c['competencies'], fn($i) => $i->status === $status)
    ) {
        continue;
    }
    $people[$u->id] = [$u, $c];
}
$notsetup = count($learners) - count($data);

// Competencies that matter for the people shown, in the matrix's order.
$allcomps = matrix::competencies($scope, $posid);
$used = [];
foreach ($people as [, $c]) {
    $used += array_fill_keys(array_keys($c['competencies']), true);
}
$comps = array_intersect_key($allcomps, $used);

$labels = [];
foreach (matrix::STATUSES as $s) {
    $labels[$s] = get_string('comp_' . $s, 'local_clientportal');
}

if ($download === 'csv') {
    $csv = new csv_export_writer();
    $csv->set_filename(clean_filename(format_string($scope->client->name) . '-compliance-' . date('Y-m-d')));
    $head = [get_string('learner', 'local_clientportal'), get_string('department', 'local_clientportal'),
        get_string('position', 'local_clientportal'), get_string('startdate', 'local_clientportal'),
        get_string('compliance', 'local_clientportal')];
    foreach ($comps as $comp) {
        $head[] = format_string($comp->name);
        $head[] = format_string($comp->name) . ' - ' . get_string('expires', 'local_clientportal');
    }
    $csv->add_data($head);
    foreach ($people as [$u, $c]) {
        $s = $c['staff'];
        $line = [fullname($u), $departments[$s->departmentid] ?? '', isset($positions[$s->positionid])
            ? format_string($positions[$s->positionid]->name) : '', $s->startdate ? userdate($s->startdate, '%Y-%m-%d') : '',
            $c['stats']['compliance_rate'] . '%'];
        foreach ($comps as $compid => $comp) {
            $item = $c['competencies'][$compid] ?? null;
            $line[] = $item ? $labels[$item->status] : get_string('notrequired', 'local_clientportal');
            $line[] = $item && $item->expirydate ? userdate($item->expirydate, '%Y-%m-%d') : '';
        }
        $csv->add_data($line);
    }
    manager::log($scope->id(), 'compliance_downloaded');
    $csv->download_file();
    exit;
}

// Summary across everyone shown.
$sum = ['total' => 0, 'completed' => 0, 'expired' => 0, 'expiring_soon' => 0, 'not_started' => 0, 'in_progress' => 0];
$fully = 0;
foreach ($people as [, $c]) {
    foreach (array_keys($sum) as $k) {
        $sum[$k] += $c['stats'][$k];
    }
    $fully += $c['stats']['total'] && $c['stats']['completed'] === $c['stats']['total'] ? 1 : 0;
}
$rate = $sum['total'] ? (int)round($sum['completed'] * 100 / $sum['total']) : 0;

$deptoptions = [0 => get_string('alldepartments', 'local_clientportal')] + $departments;
$posoptions = [0 => get_string('allpositions', 'local_clientportal')];
foreach ($positions as $p) {
    if (!$deptid || (int)$p->departmentid === $deptid) {
        $posoptions[$p->id] = format_string($p->name);
    }
}
$statusoptions = ['' => get_string('allstatuses', 'local_clientportal'),
    'noncompliant' => get_string('show_noncompliant', 'local_clientportal')] + $labels;
$baseurl = $scope->url('/local/clientportal/compliance.php', $params);
$filters = ui::filters($scope, $baseurl, $groupid, [
    'department' => [$deptoptions, $deptid, get_string('department', 'local_clientportal')],
    'position' => [$posoptions, $posid, get_string('position', 'local_clientportal')],
    'status' => [$statusoptions, $status, get_string('status', 'local_clientportal')],
]);
$export = html_writer::link(
    $scope->url('/local/clientportal/compliance.php', $params + ['download' => 'csv']),
    get_string('exportcsv', 'local_clientportal'),
    ['class' => 'btn btn-outline-secondary']
);
echo ui::header($scope, 'compliance', get_string('nav_compliance', 'local_clientportal'), $export);

echo html_writer::start_div('cport-stats');
echo ui::stat(
    get_string('stat_compliance', 'local_clientportal'),
    $rate . '%',
    get_string('stat_fullycompliant', 'local_clientportal', ['n' => $fully, 'of' => count($people)]),
    'good'
);
echo ui::stat(
    get_string('comp_expired', 'local_clientportal'),
    $sum['expired'],
    get_string('stat_expired_sub', 'local_clientportal'),
    $sum['expired'] ? 'warn' : 'neutral'
);
echo ui::stat(
    get_string('comp_expiring_soon', 'local_clientportal'),
    $sum['expiring_soon'],
    get_string('stat_expiring_sub', 'local_clientportal'),
    $sum['expiring_soon'] ? 'warn' : 'neutral'
);
echo ui::stat(get_string('comp_in_progress', 'local_clientportal'), $sum['in_progress'], '', 'info');
echo ui::stat(
    get_string('comp_not_started', 'local_clientportal'),
    $sum['not_started'],
    '',
    $sum['not_started'] ? 'warn' : 'neutral'
);
echo html_writer::end_div();

$toolbar = html_writer::div($filters . ui::search($baseurl, $search) . html_writer::span(
    get_string('peoplecount', 'local_clientportal', count($people)),
    'cport-muted'
), 'cport-toolbar');
if ($notsetup > 0 && $scope->is_manager()) {
    $toolbar .= html_writer::div(
        get_string('notsetupcount', 'local_clientportal', $notsetup) . ' ' .
        html_writer::link(
            $scope->url('/local/clientportal/onboarding.php', ['show' => 'notsetup']),
            get_string('setupnow', 'local_clientportal')
        ),
        'alert alert-info py-2'
    );
}

if (!$people) {
    echo ui::card('', $toolbar . ui::empty(get_string(
        $data ? 'nolearnersmatch' : 'nocompliance',
        'local_clientportal'
    )));
    echo ui::footer();
    exit;
}

$table = new html_table();
$table->attributes['class'] = 'table cport-table cport-matrix';
$table->head = [get_string('learner', 'local_clientportal'), get_string('compliance', 'local_clientportal')];
foreach ($comps as $comp) {
    $table->head[] = html_writer::span(format_string($comp->name), 'cport-matrix-head', ['title' =>
        trim(format_string($comp->code ?? '') . ' ' . format_string($comp->category ?? ''))]);
}
$fmt = get_string('strftimedate', 'langconfig');
foreach (array_slice($people, $page * $perpage, $perpage, true) as [$u, $c]) {
    $s = $c['staff'];
    $who = html_writer::link($scope->url('/local/clientportal/learner.php', ['id' => $u->id]), fullname($u)) .
        html_writer::div(s(isset($positions[$s->positionid]) ? format_string($positions[$s->positionid]->name) : '') .
            (isset($departments[$s->departmentid]) ? ' · ' . s($departments[$s->departmentid]) : ''), 'cport-muted');
    $row = [html_writer::div($who, 'cport-name'), ui::progress((int)$c['stats']['compliance_rate'])];
    foreach ($comps as $compid => $comp) {
        $item = $c['competencies'][$compid] ?? null;
        if (!$item) {
            $row[] = html_writer::span('·', 'cport-chip-none', ['title' => get_string('notrequired', 'local_clientportal')]);
            continue;
        }
        $title = $item->expirydate ? get_string('expireson', 'local_clientportal', userdate($item->expirydate, $fmt))
            : ($item->status === 'in_progress' ? $item->progress . '%' : '');
        $row[] = ui::chip($item->status, $title);
    }
    $table->data[] = $row;
}
echo ui::card('', $toolbar . html_writer::div(html_writer::table($table), 'cport-table-wrap'));
echo $OUTPUT->paging_bar(count($people), $page, $perpage, $baseurl);
echo ui::footer();
