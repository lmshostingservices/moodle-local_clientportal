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
 * Client Portal - learners list, with bulk "add to group".
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

require_login(null, false);
// Access comes from client membership, checked by scope::require_portal(), so no require_capability() here.

$groupid = optional_param('group', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
$sort = optional_param('sort', 'name', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;

$params = array_filter(['group' => $groupid, 'search' => $search, 'sort' => $sort !== 'name' ? $sort : null]);
$scope = scope::require_portal(new moodle_url('/local/clientportal/learners.php', $params));
$baseurl = $scope->url('/local/clientportal/learners.php', $params);
if ($groupid) {
    manager::get_group($scope->id(), $groupid);
}

// Bulk group actions.
if (optional_param('bulk', '', PARAM_ALPHA) && confirm_sesskey()) {
    $userids = optional_param_array('users', [], PARAM_INT);
    $target = required_param('targetgroup', PARAM_INT);
    if ($userids && $target) {
        if (optional_param('bulk', '', PARAM_ALPHA) === 'remove') {
            $n = manager::remove_from_group($scope->id(), $target, $userids);
            $msg = get_string('removedfromgroup', 'local_clientportal', $n);
        } else {
            $n = manager::add_to_group($scope->id(), $target, $userids);
            $msg = get_string('addedtogroup', 'local_clientportal', $n);
        }
        redirect($baseurl, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect(
        $baseurl,
        get_string('selectlearnersandgroup', 'local_clientportal'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$report = new report($scope);
$total = $report->count_learners($groupid ?: null, $search);
$learners = $report->learners($groupid ?: null, $search, $sort, $page, $perpage);
$groups = manager::get_groups($scope->id());
$showemail = (bool)get_config('local_clientportal', 'showemail');
$hasmatrix = matrix::available();
$compliance = $hasmatrix ? matrix::compliance($scope, array_map(fn($u) => (int)$u->id, $learners)) : [];
$positions = $hasmatrix ? matrix::positions($scope) : [];
$departments = $hasmatrix ? matrix::departments($scope) : [];

$export = html_writer::link(
    $scope->url(
        '/local/clientportal/report.php',
        ['download' => 'csv'] + ($groupid ? ['group' => $groupid] : [])
    ),
    get_string('exportcsv', 'local_clientportal'),
    ['class' => 'btn btn-outline-secondary']
);
echo ui::header($scope, 'learners', get_string('nav_learners', 'local_clientportal'), $export);

// Filters: group + search.
$toolbar = ui::filters($scope, $baseurl, $groupid);
$toolbar .= html_writer::start_tag('form', ['method' => 'get', 'class' => 'cport-filters', 'role' => 'search']);
if ($groupid) {
    $toolbar .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'group', 'value' => $groupid]);
}
if ($scope->support) {
    $toolbar .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'client', 'value' => $scope->id()]);
}
$toolbar .= html_writer::label(get_string('search'), 'cport-search', true, ['class' => 'sr-only']);
$toolbar .= html_writer::empty_tag('input', ['type' => 'search', 'name' => 'search', 'id' => 'cport-search', 'value' => $search,
    'class' => 'form-control', 'placeholder' => get_string('searchlearners', 'local_clientportal')]);
$toolbar .= html_writer::tag('button', get_string('search'), ['class' => 'btn btn-secondary', 'type' => 'submit']);
$toolbar .= html_writer::end_tag('form');

$body = html_writer::div(
    $toolbar . html_writer::span(get_string('learnercount', 'local_clientportal', $total), 'cport-muted'),
    'cport-toolbar'
);

if (!$learners) {
    $body .= ui::empty(get_string($search || $groupid ? 'nolearnersmatch' : 'nolearners', 'local_clientportal'));
    echo ui::card('', $body);
    echo ui::footer();
    exit;
}

$sortlink = function (string $key, string $label) use ($scope, $params) {
    $p = $params;
    $p['sort'] = $key;
    return html_writer::link($scope->url('/local/clientportal/learners.php', $p), $label);
};

$body .= html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false)]);
$body .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$table = new html_table();
$table->attributes['class'] = 'table cport-table';
$selectall = html_writer::checkbox('', 1, false, '', ['class' => 'cport-selectall', 'title' => get_string('selectall'),
    'onclick' => "this.closest('table').querySelectorAll('input[name=\"users[]\"]').forEach(c => c.checked = this.checked)"]);
$table->head = [$selectall, $sortlink('name', get_string('learner', 'local_clientportal')),
    get_string('groups', 'local_clientportal')];
if ($hasmatrix) {
    $table->head[] = get_string('jobrole', 'local_clientportal');
    $table->head[] = get_string('compliance', 'local_clientportal');
}
$table->head = array_merge($table->head, [get_string('nav_courses', 'local_clientportal'),
    get_string('status_completed', 'local_clientportal'), get_string('status_inprogress', 'local_clientportal'),
    get_string('status_notstarted', 'local_clientportal'), $sortlink('lastaccess', get_string('lastlogin', 'local_clientportal'))]);
foreach ($learners as $u) {
    $name = html_writer::link($scope->url('/local/clientportal/learner.php', ['id' => $u->id]), fullname($u));
    if ($showemail) {
        $name .= html_writer::div(s($u->email), 'cport-muted');
    }
    if ($u->suspended) {
        $name .= ' ' . html_writer::span(get_string('suspended', 'local_clientportal'), 'cport-pill cport-pill-suspended');
    }
    $tags = '';
    foreach ($u->groups as $gname) {
        $tags .= html_writer::span(s($gname), 'cport-tag');
    }
    $row = [
        html_writer::checkbox('users[]', $u->id, false, '', ['aria-label' => fullname($u)]),
        html_writer::div($name, 'cport-name'),
        $tags ?: html_writer::span('–', 'text-muted'),
    ];
    if ($hasmatrix) {
        $c = $compliance[$u->id] ?? null;
        if ($c) {
            $st = $c['staff'];
            $posname = isset($positions[$st->positionid]) ? format_string($positions[$st->positionid]->name) : '–';
            $row[] = html_writer::div(s($posname)) .
                html_writer::div(s($departments[$st->departmentid] ?? ''), 'cport-muted');
            $row[] = $c['stats']['total'] ? ui::progress((int)$c['stats']['compliance_rate']) : '–';
        } else {
            $row[] = $scope->is_manager() ? html_writer::link(
                $scope->url('/local/clientportal/onboarding.php', ['id' => $u->id]),
                get_string('setup', 'local_clientportal'),
                ['class' => 'btn btn-sm btn-outline-primary']
            ) : html_writer::span(get_string('notsetup', 'local_clientportal'), 'cport-muted');
            $row[] = html_writer::span('–', 'text-muted');
        }
    }
    $table->data[] = array_merge($row, [
        $u->courses, $u->completed, $u->inprogress, $u->notstarted,
        ui::date($u->lastaccess ? (int)$u->lastaccess : null),
    ]);
}
$body .= html_writer::div(html_writer::table($table), 'cport-table-wrap');

if ($groups) {
    $options = [0 => get_string('choosegroup', 'local_clientportal')];
    foreach ($groups as $g) {
        $options[$g->id] = format_string($g->name);
    }
    $bulk = html_writer::label(get_string('group', 'local_clientportal'), 'cport-target', true, ['class' => 'sr-only']);
    $bulk .= html_writer::select(
        $options,
        'targetgroup',
        $groupid,
        false,
        ['id' => 'cport-target', 'class' => 'custom-select form-select']
    );
    $bulk .= html_writer::tag(
        'button',
        get_string('addtogroup', 'local_clientportal'),
        ['type' => 'submit', 'name' => 'bulk', 'value' => 'add', 'class' => 'btn btn-primary']
    );
    $bulk .= html_writer::tag(
        'button',
        get_string('removefromgroup', 'local_clientportal'),
        ['type' => 'submit', 'name' => 'bulk', 'value' => 'remove', 'class' => 'btn btn-outline-secondary']
    );
    $body .= html_writer::div(
        html_writer::span(get_string('withselected', 'local_clientportal'), 'cport-muted') . $bulk,
        'cport-toolbar cport-filters'
    );
}
$body .= html_writer::end_tag('form');

echo ui::card('', $body);
echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);
echo ui::footer();
