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
 * Client Portal - compliance rules: required courses, due dates, renewals and reminders.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\compliance\engine;
use local_clientportal\compliance\rules;
use local_clientportal\compliance\view;
use local_clientportal\form\rule_form;
use local_clientportal\integration\matrix;
use local_clientportal\scope;
use local_clientportal\ui;

require(__DIR__ . '/../../config.php');

require_login(null, false);
// Access comes from client membership, checked by scope::require_portal(), so no require_capability() here.

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$scope = scope::require_portal(new moodle_url('/local/clientportal/compliancerules.php'), true);
$listurl = $scope->url('/local/clientportal/compliancerules.php');
$title = get_string('nav_compliance', 'local_clientportal');

// Delete.
if ($action === 'delete' && $id) {
    $rule = rules::get($scope, $id);
    if (optional_param('confirm', 0, PARAM_BOOL) && confirm_sesskey()) {
        rules::delete($scope, $id);
        redirect($listurl, get_string('rule_deleted', 'local_clientportal'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo ui::header($scope, 'compliance', get_string('rule_delete', 'local_clientportal'));
    echo $OUTPUT->confirm(
        get_string('rule_deleteconfirm', 'local_clientportal', rules::course_name((int)$rule->courseid)),
        $scope->url('/local/clientportal/compliancerules.php', ['action' => 'delete', 'id' => $id, 'confirm' => 1,
            'sesskey' => sesskey()]),
        $listurl
    );
    echo ui::footer();
    exit;
}

// Turn on or off, and run now.
if (in_array($action, ['enable', 'disable', 'run'], true) && $id && confirm_sesskey()) {
    if ($action === 'run') {
        rules::get($scope, $id);
        $stats = (new engine())->run($scope->id(), $id);
        redirect(
            $listurl,
            get_string('rule_ran', 'local_clientportal', (object)$stats),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    rules::set_enabled($scope, $id, $action === 'enable');
    redirect($listurl);
}

// Create or edit.
if ($action === 'edit') {
    $competencies = [];
    if (matrix::available()) {
        foreach (matrix::competencies($scope) as $c) {
            $competencies[$c->id] = format_string($c->name);
        }
    }
    $form = new rule_form($listurl->out(false), [
        'courses' => rules::course_options($scope),
        'audiences' => rules::audience_options($scope),
        'competencies' => $competencies,
    ]);
    if ($form->is_cancelled()) {
        redirect($listurl);
    }
    if ($data = $form->get_data()) {
        $ruleid = rules::save($scope, $data);
        if (!empty($data->enabled)) {
            (new engine())->run($scope->id(), $ruleid);
        }
        redirect(
            $scope->url('/local/clientportal/compliancedue.php', ['rule' => $ruleid]),
            get_string('rule_saved', 'local_clientportal', rules::course_name((int)$data->courseid)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    $defaults = ['action' => 'edit', 'client' => $scope->support ? $scope->id() : 0];
    if ($id) {
        $rule = rules::get($scope, $id);
        $defaults += (array)$rule;
        if ($rule->audience !== 'all') {
            $defaults['audience_' . $rule->audience] = $rule->audienceid;
        }
        $defaults['reminders'] = str_replace(',', ', ', $rule->reminders);
        if (!$rule->duedate) {
            unset($defaults['duedate']);
        }
    }
    $form->set_data($defaults);
    echo ui::header($scope, 'compliance', get_string($id ? 'rule_edit' : 'rule_add', 'local_clientportal'));
    echo html_writer::tag('p', get_string('rule_formintro', 'local_clientportal'), ['class' => 'cport-subtitle']);
    echo ui::card('', html_writer::div($form->render(), 'cport-pad'));
    echo ui::footer();
    exit;
}

// List.
$all = rules::all($scope);
$counts = [];
foreach (view::rows($scope) as $row) {
    $counts[$row->ruleid][$row->state] = ($counts[$row->ruleid][$row->state] ?? 0) + 1;
}
$add = html_writer::link(
    $scope->url('/local/clientportal/compliancerules.php', ['action' => 'edit']),
    get_string('rule_add', 'local_clientportal'),
    ['class' => 'btn btn-primary']
);
echo ui::header($scope, 'compliance', $title, $add);
echo view::tabs($scope, 'rules');
echo html_writer::tag('p', get_string('rules_intro', 'local_clientportal'), ['class' => 'cport-subtitle']);
if (!$all) {
    echo ui::card('', ui::empty(get_string('rules_none', 'local_clientportal')));
    echo ui::footer();
    exit;
}
$options = rules::audience_options($scope);
$table = new html_table();
$table->attributes['class'] = 'table cport-table';
$table->head = [
    get_string('rule_course', 'local_clientportal'),
    get_string('rule_audience', 'local_clientportal'),
    get_string('rule_when', 'local_clientportal'),
    get_string('rule_recur', 'local_clientportal'),
    get_string('rule_progress', 'local_clientportal'),
    '',
];
foreach ($all as $rule) {
    $c = $counts[$rule->id] ?? [];
    $total = array_sum($c);
    $progress = $total ? get_string('rule_progress_done', 'local_clientportal', (object)[
        'done' => $c['completed'] ?? 0, 'total' => $total]) . html_writer::div(get_string(
            'rule_progress_overdue',
            'local_clientportal',
            $c['overdue'] ?? 0
        ), 'small ' . (empty($c['overdue']) ? 'text-muted' : 'text-warning')) : '–';
    $link = fn(string $act, string $label, string $class, bool $sesskey = true) => html_writer::link(
        $scope->url('/local/clientportal/compliancerules.php', ['action' => $act, 'id' => $rule->id]
            + ($sesskey ? ['sesskey' => sesskey()] : [])),
        $label,
        ['class' => 'btn btn-sm ' . $class]
    );
    $actions = html_writer::link(
        $scope->url('/local/clientportal/compliancedue.php', ['rule' => $rule->id]),
        get_string('rule_view', 'local_clientportal'),
        ['class' => 'btn btn-sm btn-outline-primary']
    ) . ' ' . $link('edit', get_string('edit'), 'btn-outline-secondary', false) . ' ' .
        ($rule->enabled
            ? $link('run', get_string('rule_run', 'local_clientportal'), 'btn-outline-secondary') . ' ' .
                $link('disable', get_string('rule_disable', 'local_clientportal'), 'btn-outline-secondary')
            : $link('enable', get_string('rule_enable', 'local_clientportal'), 'btn-outline-secondary')) . ' ' .
        $link('delete', get_string('delete'), 'btn-outline-danger', false);
    $name = format_string($rule->coursename) . ($rule->enabled ? '' : ' ' .
        html_writer::span(get_string('rule_off', 'local_clientportal'), 'badge bg-secondary'));
    $table->data[] = [
        $name,
        s(rules::describe_audience($scope, $rule, $options)),
        s(rules::describe_due($rule)),
        s(rules::describe_recur($rule)),
        $progress,
        html_writer::div($actions, 'text-nowrap'),
    ];
}
echo ui::card('', html_writer::table($table));
echo ui::footer();
