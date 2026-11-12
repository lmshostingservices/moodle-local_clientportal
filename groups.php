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
 * Client Portal - client groups (for example Sales and Warehouse).
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\form\group_form;
use local_clientportal\manager;
use local_clientportal\report;
use local_clientportal\scope;
use local_clientportal\ui;

require(__DIR__ . '/../../config.php');

require_login(null, false);
// pipeline-ignore: require_capability — access comes from client membership, checked by scope::require_portal().

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$scope = scope::require_portal(new moodle_url('/local/clientportal/groups.php'));
$listurl = $scope->url('/local/clientportal/groups.php');

// Delete.
if ($action === 'delete' && $id) {
    $group = manager::get_group($scope->id(), $id);
    if (optional_param('confirm', 0, PARAM_BOOL) && confirm_sesskey()) {
        manager::delete_group($scope->id(), $id);
        redirect(
            $listurl,
            get_string('groupdeleted', 'local_clientportal', format_string($group->name)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo ui::header($scope, 'groups', get_string('deletegroup', 'local_clientportal'));
    echo $OUTPUT->confirm(
        get_string('deletegroupconfirm', 'local_clientportal', format_string($group->name)),
        $scope->url('/local/clientportal/groups.php', ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]),
        $listurl
    );
    echo ui::footer();
    exit;
}

// Create or edit.
if ($action === 'edit') {
    $form = new group_form($scope->url('/local/clientportal/groups.php')->out(false));
    if ($form->is_cancelled()) {
        redirect($listurl);
    }
    if ($data = $form->get_data()) {
        manager::save_group($scope->id(), $data);
        redirect(
            $listurl,
            get_string('groupsaved', 'local_clientportal', format_string($data->name)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    $defaults = ['action' => 'edit', 'client' => $scope->support ? $scope->id() : 0];
    if ($id) {
        $defaults += (array)manager::get_group($scope->id(), $id);
    }
    $form->set_data($defaults);
    echo ui::header($scope, 'groups', get_string($id ? 'editgroup' : 'creategroup', 'local_clientportal'));
    echo ui::card('', html_writer::div($form->render(), 'cport-pad'));
    echo ui::footer();
    exit;
}

// Members of one group.
if ($action === 'members' && $id) {
    $group = manager::get_group($scope->id(), $id);
    $memberurl = $scope->url('/local/clientportal/groups.php', ['action' => 'members', 'id' => $id]);
    if (($do = optional_param('do', '', PARAM_ALPHA)) && confirm_sesskey()) {
        $userids = optional_param_array('users', [], PARAM_INT);
        if ($do === 'add') {
            $n = manager::add_to_group($scope->id(), $id, $userids);
            $msg = get_string('addedtogroup', 'local_clientportal', $n);
        } else {
            $n = manager::remove_from_group($scope->id(), $id, $userids);
            $msg = get_string('removedfromgroup', 'local_clientportal', $n);
        }
        redirect($memberurl, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
    }
    $report = new report($scope);
    $in = $report->learners($id, '', 'name', 0, 5000);
    $all = $report->learners(null, '', 'name', 0, 5000);
    $out = array_diff_key($all, $in);

    $list = function (array $users, string $name, string $do, string $button, string $btnclass) use ($memberurl) {
        $html = html_writer::start_tag('form', ['method' => 'post', 'action' => $memberurl->out(false)]);
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => $do]);
        $options = [];
        foreach ($users as $u) {
            $options[$u->id] = fullname($u) . ($u->groups ? ' (' . implode(', ', $u->groups) . ')' : '');
        }
        $html .= html_writer::label($name, 'cport-' . $do, true, ['class' => 'sr-only']);
        $html .= html_writer::select(
            $options,
            'users[]',
            '',
            false,
            ['multiple' => 'multiple', 'size' => 16, 'class' => 'form-control', 'id' => 'cport-' . $do]
        );
        $html .= html_writer::div(
            html_writer::tag('button', $button, ['type' => 'submit', 'class' => 'btn ' . $btnclass]),
            'mt-2'
        );
        $html .= html_writer::end_tag('form');
        return $html;
    };
    $back = html_writer::link($listurl, get_string('backtogroups', 'local_clientportal'), ['class' => 'btn btn-outline-secondary']);
    echo ui::header($scope, 'groups', format_string($group->name), $back);
    echo html_writer::tag('p', get_string('membershelp', 'local_clientportal'), ['class' => 'cport-subtitle']);
    echo html_writer::start_div('cport-grid');
    echo ui::card(
        get_string('ingroup', 'local_clientportal', count($in)),
        html_writer::div(
            $list(
                $in,
                get_string('ingroup', 'local_clientportal', count($in)),
                'remove',
                get_string('removeselected', 'local_clientportal'),
                'btn-outline-secondary'
            ),
            'cport-pad'
        )
    );
    echo ui::card(
        get_string('notingroup', 'local_clientportal', count($out)),
        html_writer::div(
            $list(
                $out,
                get_string('notingroup', 'local_clientportal', count($out)),
                'add',
                get_string('addselected', 'local_clientportal'),
                'btn-primary'
            ),
            'cport-pad'
        )
    );
    echo html_writer::end_div();
    echo ui::footer();
    exit;
}

// List.
$groups = manager::get_groups($scope->id());
$add = html_writer::link(
    $scope->url('/local/clientportal/groups.php', ['action' => 'edit']),
    get_string('creategroup', 'local_clientportal'),
    ['class' => 'btn btn-primary']
);
echo ui::header($scope, 'groups', get_string('nav_groups', 'local_clientportal'), $add);
echo html_writer::tag('p', get_string('groupsintro', 'local_clientportal'), ['class' => 'cport-subtitle']);
if (!$groups) {
    echo ui::card('', ui::empty(get_string('nogroupsyet', 'local_clientportal')));
    echo ui::footer();
    exit;
}
$table = new html_table();
$table->attributes['class'] = 'table cport-table';
$table->head = [get_string('groupname', 'local_clientportal'), get_string('costcentre', 'local_clientportal'),
    get_string('stat_learners', 'local_clientportal'), ''];
foreach ($groups as $g) {
    $actions = html_writer::link(
        $scope->url('/local/clientportal/groups.php', ['action' => 'members', 'id' => $g->id]),
        get_string('managemembers', 'local_clientportal'),
        ['class' => 'btn btn-sm btn-outline-primary']
    ) . ' ' .
        html_writer::link(
            $scope->url('/local/clientportal/groups.php', ['action' => 'edit', 'id' => $g->id]),
            get_string('edit'),
            ['class' => 'btn btn-sm btn-outline-secondary']
        ) . ' ' .
        html_writer::link(
            $scope->url('/local/clientportal/groups.php', ['action' => 'delete', 'id' => $g->id]),
            get_string('delete'),
            ['class' => 'btn btn-sm btn-outline-danger']
        );
    $name = html_writer::link(
        $scope->url('/local/clientportal/learners.php', ['group' => $g->id]),
        format_string($g->name),
        ['class' => 'cport-name']
    );
    if (trim((string)$g->description) !== '') {
        $name .= html_writer::div(s($g->description), 'cport-muted');
    }
    $table->data[] = [$name, s($g->costcentre) ?: html_writer::span('–', 'text-muted'), $g->members, $actions];
}
echo ui::card('', html_writer::div(html_writer::table($table), 'cport-table-wrap'));
echo ui::footer();
