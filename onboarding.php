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
 * Client Portal - onboarding: give each learner a role (department, position, start date, manager) and pathways.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\integration\enrolment;
use local_clientportal\integration\matrix;
use local_clientportal\integration\pathways;
use local_clientportal\onboarding;
use local_clientportal\report;
use local_clientportal\scope;
use local_clientportal\ui;

require(__DIR__ . '/../../config.php');

require_login(null, false);
// Access comes from client membership, checked by scope::require_portal(), so no require_capability() here.

$userid = optional_param('id', 0, PARAM_INT);
$show = optional_param('show', 'all', PARAM_ALPHA);
$groupid = optional_param('group', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;
$params = array_filter(['id' => $userid, 'show' => $show !== 'all' ? $show : null, 'group' => $groupid, 'search' => $search]);
$scope = scope::require_portal(new moodle_url('/local/clientportal/onboarding.php', $params), true);
$hasmatrix = matrix::available();
$haspathways = pathways::available();
if (!$hasmatrix && !$haspathways) {
    redirect($scope->url('/local/clientportal/index.php'));
}

if ($userid) {
    // One learner: the onboarding form.
    $learner = $scope->require_learner($userid);
    $staff = $hasmatrix ? (matrix::staff($scope, [$userid])[$userid] ?? null) : null;
    $positions = [];
    if ($hasmatrix) {
        $departments = matrix::departments($scope);
        foreach (matrix::positions($scope) as $p) {
            $dept = $departments[$p->departmentid] ?? '';
            $positions[$p->id] = ($dept !== '' ? $dept . ': ' : '') . format_string($p->name);
        }
    }
    $onpathways = [];
    $available = [];
    if ($haspathways) {
        foreach (pathways::assignments($scope, [$userid]) as $a) {
            if ($a->status === 'active') {
                $onpathways[(int)$a->pathwayid] = true;
            }
        }
        foreach (pathways::all($scope) as $p) {
            if (!isset($onpathways[(int)$p->id])) {
                $available[$p->id] = format_string($p->name);
            }
        }
    }
    $returnurl = $scope->url('/local/clientportal/learner.php', ['id' => $userid]);
    $form = new local_clientportal\form\onboard_form($scope->url('/local/clientportal/onboarding.php', ['id' => $userid]), [
        'matrix' => $hasmatrix,
        'positions' => $positions,
        'managers' => $hasmatrix ? matrix::manager_options($scope) : [],
        'pathways' => $available,
        'canenrol' => enrolment::allowed(),
        'usual' => onboarding::usual_map($scope),
    ]);
    $defaults = ['id' => $userid, 'client' => $scope->support ? $scope->id() : 0, 'startdate' => time()];
    if ($staff) {
        $defaults += ['positionid' => $staff->positionid, 'startdate' => $staff->startdate ?: time(),
            'managerid' => $staff->managerid ?? 0, 'employeeid' => $staff->employeeid ?? ''];
        $defaults['startdate'] = (int)$staff->startdate ?: time();
    }
    $form->set_data($defaults);
    if ($form->is_cancelled()) {
        redirect($returnurl);
    }
    if ($data = $form->get_data()) {
        $result = onboarding::apply($scope, $userid, [
            'positionid' => $hasmatrix ? (int)($data->positionid ?? 0) : 0,
            'startdate' => $data->startdate ?? 0,
            'managerid' => $data->managerid ?? 0,
            'employeeid' => $data->employeeid ?? '',
            'pathways' => array_keys(array_filter((array)($data->pathway ?? []))),
            'targetdate' => $data->targetdate ?? 0,
            'enrol' => !empty($data->enrol),
            'usual' => !empty($data->usual),
        ]);
        [$parts, $warn] = onboarding::messages($result);
        if ($result['role']) {
            array_unshift($parts, get_string('onboard_saved', 'local_clientportal', fullname($learner)));
        }
        redirect(
            $returnurl,
            implode(' ', $parts ?: [get_string('changessaved')]),
            null,
            $warn ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS
        );
    }

    $back = html_writer::link(
        $returnurl,
        get_string('backtolearner', 'local_clientportal'),
        ['class' => 'btn btn-outline-secondary']
    );
    echo ui::header($scope, 'onboarding', get_string('onboardtitle', 'local_clientportal', fullname($learner)), $back);
    $initials = core_text::strtoupper(core_text::substr($learner->firstname, 0, 1) . core_text::substr($learner->lastname, 0, 1));
    $current = $staff && isset($positions[$staff->positionid]) ? $positions[$staff->positionid]
        : get_string('notsetup', 'local_clientportal');
    $intro = html_writer::div(
        html_writer::span(s($initials), 'cport-avatar') .
        html_writer::div(
            html_writer::div(fullname($learner), 'cport-who-name') .
            html_writer::div(s($current), 'cport-muted'),
            'cport-who-text'
        ),
        'cport-who'
    );
    $intro .= html_writer::tag('p', get_string('onboard_intro', 'local_clientportal'), ['class' => 'cport-muted']);
    if ($hasmatrix && !$positions) {
        $intro .= html_writer::div(get_string('nopositions', 'local_clientportal'), 'alert alert-info');
    }
    if ($onpathways) {
        $current = [];
        foreach (pathways::assignments($scope, [$userid]) as $a) {
            $current[] = ui::pathway_badge($scope, $a);
        }
        $intro .= html_writer::div(
            html_writer::span(get_string('currentpathways', 'local_clientportal'), 'cport-muted me-2 mr-2') .
                implode(' ', $current),
            'mb-3'
        );
    }
    echo ui::card('', html_writer::div($intro . $form->render(), 'cport-pad cport-form'));
    echo ui::footer();
    exit;
}

// Everyone: who is set up, who is new, who is on a pathway.
$report = new report($scope);
$learners = $report->learners($groupid ?: null, $search, 'name', 0, 0);
$ids = array_map(fn($u) => (int)$u->id, $learners);
$compliance = $hasmatrix ? matrix::compliance($scope, $ids) : [];
$assignments = [];
if ($haspathways) {
    foreach (pathways::assignments($scope, $ids) as $a) {
        $assignments[(int)$a->userid][] = $a;
    }
}
$positions = $hasmatrix ? matrix::positions($scope) : [];
$departments = $hasmatrix ? matrix::departments($scope) : [];
$managers = $hasmatrix ? matrix::manager_options($scope) : [];
$newsince = time() - 90 * DAYSECS;

// Bulk: give everyone selected the same position and/or pathway in one go.
if (optional_param('bulk', '', PARAM_ALPHA) === 'apply' && confirm_sesskey()) {
    $userids = optional_param_array('users', [], PARAM_INT);
    $positionid = optional_param('bulkposition', 0, PARAM_INT);
    $pathwayid = optional_param('bulkpathway', 0, PARAM_INT);
    $target = optional_param('bulktarget', '', PARAM_TEXT);
    $listurl = $scope->url('/local/clientportal/onboarding.php', array_filter(['show' => $show !== 'all' ? $show : null,
        'group' => $groupid, 'search' => $search]));
    if (!$userids || (!$positionid && !$pathwayid)) {
        redirect($listurl, get_string('bulk_choose', 'local_clientportal'), null, \core\output\notification::NOTIFY_WARNING);
    }
    $done = 0;
    $total = ['enrolled' => 0, 'blocked' => [], 'pathways' => [], 'role' => false];
    foreach ($userids as $uid) {
        $r = onboarding::apply($scope, (int)$uid, [
            'positionid' => $positionid, 'pathways' => $pathwayid ? [$pathwayid] : [],
            'targetdate' => $target !== '' ? (strtotime($target . ' 23:59') ?: 0) : 0,
            'enrol' => true, 'usual' => true,
        ]);
        $done++;
        $total['enrolled'] += $r['enrolled'];
        $total['blocked'] = array_merge($total['blocked'], $r['blocked']);
        $total['pathways'] = array_merge($total['pathways'], $r['pathways']);
    }
    $total['blocked'] = array_unique($total['blocked']);
    $total['pathways'] = array_unique($total['pathways']);
    [$parts, $warn] = onboarding::messages($total);
    array_unshift($parts, get_string('bulk_done', 'local_clientportal', $done));
    redirect($listurl, implode(' ', $parts), null, $warn ? \core\output\notification::NOTIFY_WARNING
        : \core\output\notification::NOTIFY_SUCCESS);
}

$counts = ['notsetup' => 0, 'new' => 0, 'onpathway' => 0, 'overdue' => 0];
$rows = [];
foreach ($learners as $u) {
    $staff = $compliance[$u->id]['staff'] ?? null;
    $mine = $assignments[$u->id] ?? [];
    $isnew = $staff && (int)$staff->startdate >= $newsince;
    $active = array_filter($mine, fn($a) => $a->status === 'active');
    $late = array_filter($active, fn($a) => $a->overdue);
    $notsetup = $hasmatrix ? !$staff : !$mine;
    $counts['notsetup'] += $notsetup ? 1 : 0;
    $counts['new'] += $isnew ? 1 : 0;
    $counts['onpathway'] += $active ? 1 : 0;
    $counts['overdue'] += $late ? 1 : 0;
    if (($show === 'notsetup' && !$notsetup) || ($show === 'new' && !$isnew) || ($show === 'overdue' && !$late)) {
        continue;
    }
    $rows[] = [$u, $staff, $mine, $notsetup];
}
$matching = count($rows);
$rows = array_slice($rows, $page * $perpage, $perpage);

$filters = ui::filters(
    $scope,
    $scope->url('/local/clientportal/onboarding.php', array_filter(['show' => $show !== 'all' ? $show : null,
        'search' => $search])),
    $groupid,
    ['show' => [[
        'all' => get_string('show_all', 'local_clientportal'),
        'notsetup' => get_string('show_notsetup', 'local_clientportal'),
        'new' => get_string('show_new', 'local_clientportal'),
        'overdue' => get_string('show_overdue', 'local_clientportal'),
    ],
    $show,
    get_string('show', 'local_clientportal')]]
);
$import = html_writer::link(
    $scope->url('/local/clientportal/import.php'),
    get_string('importcsv', 'local_clientportal'),
    ['class' => 'btn btn-outline-secondary']
);
echo ui::header($scope, 'onboarding', get_string('nav_onboarding', 'local_clientportal'), $filters . $import);

echo html_writer::start_div('cport-stats');
echo ui::stat(
    get_string('stat_notsetup', 'local_clientportal'),
    $counts['notsetup'],
    get_string('stat_notsetup_sub', 'local_clientportal'),
    $counts['notsetup'] ? 'warn' : 'good'
);
if ($hasmatrix) {
    echo ui::stat(
        get_string('stat_newstarters', 'local_clientportal'),
        $counts['new'],
        get_string('stat_newstarters_sub', 'local_clientportal'),
        'info'
    );
}
if ($haspathways) {
    echo ui::stat(get_string('stat_onpathway', 'local_clientportal'), $counts['onpathway'], '', 'accent');
    echo ui::stat(
        get_string('stat_overduepathway', 'local_clientportal'),
        $counts['overdue'],
        '',
        $counts['overdue'] ? 'warn' : 'neutral'
    );
}
echo html_writer::end_div();

if (!$rows) {
    echo ui::card('', ui::empty(get_string('nolearnersmatch', 'local_clientportal')));
    echo ui::footer();
    exit;
}
$table = new html_table();
$table->attributes['class'] = 'table cport-table';
$selectall = html_writer::checkbox('', 1, false, '', ['class' => 'cport-selectall', 'title' => get_string('selectall'),
    'onclick' => "this.closest('table').querySelectorAll('input[name=\"users[]\"]').forEach(c => c.checked = this.checked)"]);
$table->head = [$selectall, get_string('learner', 'local_clientportal')];
if ($hasmatrix) {
    $table->head = array_merge($table->head, [get_string('jobrole', 'local_clientportal'),
        get_string('startdate', 'local_clientportal'), get_string('directmanager', 'local_clientportal'),
        get_string('compliance', 'local_clientportal')]);
}
if ($haspathways) {
    $table->head[] = get_string('nav_pathways', 'local_clientportal');
}
$table->head[] = '';
foreach ($rows as [$u, $staff, $mine, $notsetup]) {
    $row = [html_writer::checkbox('users[]', $u->id, false, '', ['aria-label' => fullname($u)]), html_writer::link(
        $scope->url('/local/clientportal/learner.php', ['id' => $u->id]),
        fullname($u),
        ['class' => 'cport-name']
    )];
    if ($hasmatrix) {
        if ($staff) {
            $pos = $positions[$staff->positionid] ?? null;
            $row[] = html_writer::div(s($pos ? format_string($pos->name) : '–'), 'cport-name') .
                html_writer::div(s($departments[$staff->departmentid] ?? ''), 'cport-muted');
            $row[] = ui::date((int)$staff->startdate ?: null);
            $row[] = s($managers[(int)($staff->managerid ?? 0)] ?? '–');
            $stats = $compliance[$u->id]['stats'];
            $row[] = $stats['total'] ? ui::progress((int)$stats['compliance_rate']) : html_writer::span('–', 'text-muted');
        } else {
            $row[] = html_writer::span(get_string('notsetup', 'local_clientportal'), 'cport-pill cport-pill-notstarted');
            $dash = html_writer::span('–', 'text-muted');
            array_push($row, $dash, $dash, $dash);
        }
    }
    if ($haspathways) {
        $row[] = $mine ? implode(' ', array_map(fn($a) => ui::pathway_badge($scope, $a), $mine))
            : html_writer::span('–', 'text-muted');
    }
    $row[] = html_writer::link(
        $scope->url('/local/clientportal/onboarding.php', ['id' => $u->id]),
        get_string($notsetup ? 'setup' : 'edit', $notsetup ? 'local_clientportal' : 'moodle'),
        ['class' => 'btn btn-sm ' . ($notsetup ? 'btn-primary' : 'btn-outline-secondary')]
    );
    $table->data[] = $row;
}
$listurl = $scope->url('/local/clientportal/onboarding.php', array_filter(['show' => $show !== 'all' ? $show : null,
    'group' => $groupid, 'search' => $search]));
$body = html_writer::div(
    ui::search($listurl, $search) . html_writer::span(get_string('peoplecount', 'local_clientportal', $matching), 'cport-muted'),
    'cport-toolbar'
);
$body .= html_writer::start_tag('form', ['method' => 'post', 'action' => $listurl->out(false)]);
$body .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$body .= html_writer::div(html_writer::table($table), 'cport-table-wrap');
$bulk = html_writer::span(get_string('withselected', 'local_clientportal'), 'cport-muted');
if ($hasmatrix) {
    $options = [0 => get_string('bulk_keepposition', 'local_clientportal')];
    foreach ($positions as $p) {
        $dept = $departments[$p->departmentid] ?? '';
        $options[$p->id] = format_string($p->name) . ($dept !== '' ? ' · ' . $dept : '');
    }
    $bulk .= html_writer::label(get_string('position', 'local_clientportal'), 'cport-bulkposition', true, ['class' => 'sr-only']);
    $bulk .= html_writer::select(
        $options,
        'bulkposition',
        0,
        false,
        ['id' => 'cport-bulkposition', 'class' => 'custom-select form-select']
    );
}
if ($haspathways) {
    $options = [0 => get_string('bulk_nopathway', 'local_clientportal')];
    foreach (pathways::all($scope) as $p) {
        $options[$p->id] = format_string($p->name);
    }
    $bulk .= html_writer::label(get_string('pathway', 'local_clientportal'), 'cport-bulkpathway', true, ['class' => 'sr-only']);
    $bulk .= html_writer::select(
        $options,
        'bulkpathway',
        0,
        false,
        ['id' => 'cport-bulkpathway', 'class' => 'custom-select form-select']
    );
    $bulk .= html_writer::label(get_string('targetdate', 'local_clientportal'), 'cport-bulktarget', true, ['class' => 'sr-only']);
    $bulk .= html_writer::empty_tag('input', ['type' => 'date', 'name' => 'bulktarget', 'id' => 'cport-bulktarget',
        'class' => 'form-control', 'title' => get_string('targetdate', 'local_clientportal')]);
}
$bulk .= html_writer::tag(
    'button',
    get_string('bulk_apply', 'local_clientportal'),
    ['type' => 'submit', 'name' => 'bulk', 'value' => 'apply', 'class' => 'btn btn-primary']
);
$body .= html_writer::div($bulk, 'cport-toolbar cport-filters cport-bulkbar');
$body .= html_writer::tag('p', get_string('bulk_help', 'local_clientportal'), ['class' => 'cport-muted cport-pad mb-0 pt-0']);
$body .= html_writer::end_tag('form');
echo ui::card('', $body);
echo $OUTPUT->paging_bar($matching, $page, $perpage, $listurl);
echo ui::footer();
