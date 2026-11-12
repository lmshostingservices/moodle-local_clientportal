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
 * Client Portal - team (client managers and representatives) and the audit log. Managers only.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\manager;
use local_clientportal\scope;
use local_clientportal\ui;

require(__DIR__ . '/../../config.php');

require_login(null, false);
// Access comes from client membership, checked by scope::require_portal(), so no require_capability() here.

$page = optional_param('page', 0, PARAM_INT);
$scope = scope::require_portal(new moodle_url('/local/clientportal/team.php'), true);
$perpage = 50;

echo ui::header($scope, 'team', get_string('nav_team', 'local_clientportal'));

$members = manager::get_members($scope->id());
$table = new html_table();
$table->attributes['class'] = 'table cport-table';
$table->head = [get_string('name'), get_string('email'), get_string('role', 'local_clientportal'),
    get_string('lastlogin', 'local_clientportal')];
foreach ($members as $m) {
    $table->data[] = [html_writer::span(fullname($m), 'cport-name'), s($m->email),
        get_string('role_' . $m->role, 'local_clientportal'), ui::date($m->lastaccess ? (int)$m->lastaccess : null, true)];
}
echo ui::card(
    get_string('teamtitle', 'local_clientportal'),
    html_writer::div(html_writer::table($table), 'cport-table-wrap') .
    html_writer::div(get_string('teamhelp', 'local_clientportal'), 'cport-pad cport-muted')
);

// Audit log.
$total = $DB->count_records('local_clientportal_log', ['clientid' => $scope->id()]);
$logs = $DB->get_records(
    'local_clientportal_log',
    ['clientid' => $scope->id()],
    'timecreated DESC, id DESC',
    '*',
    $page * $perpage,
    $perpage
);
$userids = [];
foreach ($logs as $l) {
    $userids[$l->userid] = true;
    if ($l->relateduserid) {
        $userids[$l->relateduserid] = true;
    }
}
$users = [];
if ($userids) {
    $fields = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;
    [$in, $inparams] = $DB->get_in_or_equal(array_keys($userids));
    $users = $DB->get_records_select('user', "id $in", $inparams, '', "id, $fields");
}
if ($logs) {
    $t = new html_table();
    $t->attributes['class'] = 'table cport-table';
    $t->head = [get_string('when', 'local_clientportal'), get_string('who', 'local_clientportal'),
        get_string('action', 'local_clientportal'), get_string('learner', 'local_clientportal'),
        get_string('details', 'local_clientportal')];
    $sm = get_string_manager();
    foreach ($logs as $l) {
        $action = $sm->string_exists('log_' . $l->action, 'local_clientportal')
            ? get_string('log_' . $l->action, 'local_clientportal') : s($l->action);
        $who = isset($users[$l->userid]) ? fullname($users[$l->userid]) : get_string('system', 'local_clientportal');
        $t->data[] = [
            ui::date((int)$l->timecreated, true),
            s($who),
            $action,
            $l->relateduserid && isset($users[$l->relateduserid]) ? s(fullname($users[$l->relateduserid])) : '–',
            s((string)$l->details),
        ];
    }
    $body = html_writer::div(html_writer::table($t), 'cport-table-wrap');
} else {
    $body = ui::empty(get_string('nolog', 'local_clientportal'));
}
echo ui::card(get_string('auditlog', 'local_clientportal'), $body);
echo $OUTPUT->paging_bar($total, $page, $perpage, $scope->url('/local/clientportal/team.php'));
echo ui::footer();
