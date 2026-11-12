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

use local_clientportal\courses;
use local_clientportal\manager;
use local_clientportal\scope;
use local_clientportal\ui;

require(__DIR__ . '/../../config.php');

require_login(null, false);
// Access comes from client membership, checked by scope::require_portal(), so no require_capability() here.

$page = optional_param('page', 0, PARAM_INT);
$scope = scope::require_portal(new moodle_url('/local/clientportal/team.php'), true);
$perpage = 50;
$teamurl = $scope->url('/local/clientportal/team.php');
$canteachers = courses::has($scope->client, courses::FEATURE_TEACHERS);
$canassessors = courses::has($scope->client, courses::FEATURE_ASSESSORS);
$action = optional_param('action', '', PARAM_ALPHA);
if ($action && confirm_sesskey() && !$scope->support) {
    if ($action === 'addtrainer' && $canteachers) {
        try {
            $user = manager::create_staff(
                $scope->id(),
                required_param('firstname', PARAM_NOTAGS),
                required_param('lastname', PARAM_NOTAGS),
                required_param('email', PARAM_EMAIL),
                manager::ROLE_TRAINER
            );
            redirect(
                $teamurl,
                get_string('staffcreated', 'local_clientportal', s($user->email)),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } catch (moodle_exception $e) {
            redirect($teamurl, $e->getMessage(), null, \core\output\notification::NOTIFY_WARNING);
        }
    }
    $userid = optional_param('user', 0, PARAM_INT);
    $member = $userid ? $DB->get_record('local_clientportal_member', ['clientid' => $scope->id(), 'userid' => $userid]) : null;
    if ($member && $action === 'assessor' && $canassessors) {
        manager::set_assessor($scope->id(), $userid, optional_param('on', 0, PARAM_BOOL));
        redirect($teamurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($member && $action === 'removetrainer' && $member->role === manager::ROLE_TRAINER) {
        manager::remove_member($scope->id(), $userid);
        redirect($teamurl, get_string('staffremoved', 'local_clientportal'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect($teamurl);
}

echo ui::header($scope, 'team', get_string('nav_team', 'local_clientportal'));

$members = manager::get_members($scope->id());
$table = new html_table();
$table->attributes['class'] = 'table cport-table';
$table->head = [get_string('name'), get_string('email'), get_string('role', 'local_clientportal'),
    get_string('lastlogin', 'local_clientportal')];
if ($canassessors) {
    $table->head[] = get_string('assessor', 'local_clientportal');
}
$table->head[] = '';

/**
 * One-button POST form.
 *
 * @param moodle_url $url
 * @param array $fields
 * @param string $label
 * @param string $class
 * @return string
 */
function local_clientportal_team_button(moodle_url $url, array $fields, string $label, string $class): string {
    $html = html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-inline']);
    foreach ($fields + ['sesskey' => sesskey()] as $k => $v) {
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
    }
    return $html . html_writer::tag('button', $label, ['type' => 'submit', 'class' => $class]) . html_writer::end_tag('form');
}

foreach ($members as $m) {
    $row = [html_writer::span(fullname($m), 'cport-name'), s($m->email),
        get_string('role_' . $m->role, 'local_clientportal'), ui::date($m->lastaccess ? (int)$m->lastaccess : null, true)];
    if ($canassessors) {
        $row[] = $scope->support ? ($m->assessor ? get_string('yes') : get_string('no')) : local_clientportal_team_button(
            $teamurl,
            ['action' => 'assessor', 'user' => $m->id, 'on' => $m->assessor ? 0 : 1],
            $m->assessor ? get_string('assessor_on', 'local_clientportal') : get_string('assessor_off', 'local_clientportal'),
            'btn btn-sm ' . ($m->assessor ? 'btn-primary' : 'btn-outline-secondary')
        );
    }
    $row[] = $m->role === manager::ROLE_TRAINER && !$scope->support ? local_clientportal_team_button(
        $teamurl,
        ['action' => 'removetrainer', 'user' => $m->id],
        get_string('remove'),
        'btn btn-sm btn-outline-secondary'
    ) : '';
    $table->data[] = $row;
}
echo ui::card(
    get_string('teamtitle', 'local_clientportal'),
    html_writer::div(html_writer::table($table), 'cport-table-wrap') .
    html_writer::div(get_string('teamhelp', 'local_clientportal') . ($canassessors ? ' ' .
        get_string('assessorhelp', 'local_clientportal') : ''), 'cport-pad cport-muted')
);

if ($canteachers && !$scope->support) {
    $f = html_writer::start_tag('form', ['method' => 'post', 'action' => $teamurl->out(false), 'class' => 'cport-trainerform']);
    $f .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $f .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'addtrainer']);
    $fieldlabels = ['firstname' => get_string('firstname'), 'lastname' => get_string('lastname'),
        'email' => get_string('email')];
    foreach (
        $fieldlabels as $name => $label
    ) {
        $f .= html_writer::div(
            html_writer::label($label, 'cport-t-' . $name, true, ['class' => 'cport-field-label']) .
            html_writer::empty_tag('input', ['type' => $name === 'email' ? 'email' : 'text', 'name' => $name,
                'id' => 'cport-t-' . $name, 'required' => 'required', 'class' => 'form-control']),
            'cport-trainerfield'
        );
    }
    $f .= html_writer::tag('button', get_string('addtrainer', 'local_clientportal'), ['type' => 'submit',
        'class' => 'btn btn-primary']);
    $f .= html_writer::end_tag('form');
    echo ui::card(get_string('addtrainer', 'local_clientportal'), html_writer::div(
        html_writer::tag('p', get_string('addtrainer_desc', 'local_clientportal'), ['class' => 'cport-muted']) . $f,
        'cport-pad'
    ));
}

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
