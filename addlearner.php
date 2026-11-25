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
 * Client Portal - add a learner (or many), with no emails unless asked for.
 *
 * Sign-in details are shown once, on the page that adds them. They are not stored, logged or shown again.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\branding;
use local_clientportal\form\learner_form;
use local_clientportal\integration\matrix;
use local_clientportal\manager;
use local_clientportal\newlearner;
use local_clientportal\scope;
use local_clientportal\ui;

require(__DIR__ . '/../../config.php');

require_login(null, false);
// Access comes from client membership, checked by scope::require_portal(), so no require_capability() here.

$bulk = optional_param('bulk', 0, PARAM_BOOL);
$scope = scope::require_portal(new moodle_url('/local/clientportal/addlearner.php', $bulk ? ['bulk' => 1] : []));
$listurl = $scope->url('/local/clientportal/learners.php');
if (!newlearner::can_add($scope)) {
    redirect($listurl, get_string('error_cannotaddlearners', 'local_clientportal'), null,
        \core\output\notification::NOTIFY_WARNING);
}
$str = fn(string $id, $a = null) => get_string($id, 'local_clientportal', $a);
$pageurl = $scope->url('/local/clientportal/addlearner.php', $bulk ? ['bulk' => 1] : []);

$groups = [];
foreach (manager::get_groups($scope->id()) as $g) {
    $groups[(int)$g->id] = format_string($g->name);
}
$courseoptions = newlearner::course_options($scope);
$form = new learner_form($pageurl->out(false), ['bulk' => $bulk, 'groups' => $groups, 'courses' => $courseoptions]);
$form->set_data(['client' => $scope->support ? $scope->id() : 0, 'bulk' => $bulk]);
if ($form->is_cancelled()) {
    redirect($listurl);
}
$PAGE->requires->js_init_code(<<<'JS'
document.addEventListener('click', function(e) {
    var copy = e.target.closest('[data-cport-copy]');
    if (copy) {
        var text = document.getElementById(copy.getAttribute('data-cport-copy')).innerText;
        navigator.clipboard.writeText(text).then(function() {
            copy.classList.add('cport-copied');
        });
    }
    var dl = e.target.closest('[data-cport-download]');
    if (dl) {
        var rows = [];
        document.querySelectorAll('#cport-added tr').forEach(function(tr) {
            var cells = [];
            tr.querySelectorAll('th,td').forEach(function(td, i) {
                if (i < 6) {
                    cells.push('"' + td.innerText.replace(/"/g, '""') + '"');
                }
            });
            rows.push(cells.join(','));
        });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([rows.join('\r\n')], {type: 'text/csv'}));
        a.download = dl.getAttribute('data-cport-download');
        a.click();
    }
});
JS);

$signinurl = branding::login_url($scope->id())->out(false);
$data = $form->get_data();

// One learner added: show the sign-in card once.
if ($data && !$bulk) {
    $result = newlearner::add($scope, $data);
    echo ui::header($scope, 'learners', $str('addlearner'));
    $name = fullname($result->user);
    if ($result->existing) {
        $body = html_writer::div($str('addlearner_existing', s($name)), 'alert alert-info');
    } else if ($result->password === null) {
        $body = html_writer::div($str('addlearner_emailed', s($name)), 'alert alert-success');
    } else {
        $body = html_writer::div($str('addlearner_added', s($name)), 'alert alert-success');
        $lines = [
            $str('signin_where') . ' ' . $signinurl,
            get_string('username') . ': ' . $result->username,
            get_string('password') . ': ' . $result->password,
        ];
        if (!empty($data->forcechange)) {
            $lines[] = $str('signin_changenote');
        }
        $card = html_writer::tag('h3', $str('signin_title', s($name)), ['class' => 'cport-signin-title']);
        $card .= html_writer::tag('pre', s(implode("\n", $lines)), ['id' => 'cport-signin', 'class' => 'cport-signin-text']);
        $card .= html_writer::tag('button', $str('signin_copy'), ['type' => 'button', 'class' => 'btn btn-primary',
            'data-cport-copy' => 'cport-signin']) . ' ';
        $card .= html_writer::tag('button', $str('signin_print'), ['type' => 'button', 'class' => 'btn btn-outline-secondary',
            'onclick' => 'window.print()']);
        $card .= html_writer::div($str('signin_once'), 'cport-muted mt-2');
        $body .= html_writer::div($card, 'cport-signin');
    }
    if ($result->enrolled) {
        $body .= html_writer::div($str('addlearner_enrolled', $result->enrolled), 'cport-muted');
    }
    $actions = html_writer::link($pageurl, $str('addlearner_another'), ['class' => 'btn btn-primary']) . ' ';
    if (matrix::available() && $scope->is_manager()) {
        $actions .= html_writer::link($scope->url('/local/clientportal/onboarding.php', ['id' => $result->user->id]),
            $str('addlearner_setuprole'), ['class' => 'btn btn-outline-primary']) . ' ';
    }
    $actions .= html_writer::link($scope->url('/local/clientportal/learner.php', ['id' => $result->user->id]),
        $str('addlearner_open'), ['class' => 'btn btn-outline-secondary']);
    echo ui::card('', html_writer::div($body . html_writer::div($actions, 'mt-3'), 'cport-pad'));
    echo ui::footer();
    exit;
}

// Many learners: add each row, then show the results (with any passwords) once.
if ($data && $bulk) {
    $table = new html_table();
    $table->id = 'cport-added';
    $table->attributes['class'] = 'table cport-table';
    $table->head = [get_string('firstname'), get_string('lastname'), get_string('email'), get_string('username'),
        get_string('password'), get_string('status')];
    $added = 0;
    $failed = 0;
    foreach (newlearner::parse_rows((string)$data->rows) as [$line, $first, $last, $email, $username, $password]) {
        $row = clone($data);
        $row->firstname = $first;
        $row->lastname = $last;
        $row->email = $email;
        $row->username = $username;
        $row->password = $password;
        try {
            $result = newlearner::add($scope, $row);
            $added++;
            $status = $result->existing ? $str('addmany_existing') : $str('addmany_added');
            $table->data[] = [s($result->user->firstname), s($result->user->lastname), s($result->user->email),
                $result->existing ? '' : s($result->username), s((string)$result->password), $status];
        } catch (moodle_exception $e) {
            $failed++;
            $table->data[] = [s($first), s($last), s($email), s($username), '',
                html_writer::span($str('addmany_line', $line) . ' ' . s($e->getMessage()), 'text-danger')];
        }
    }
    echo ui::header($scope, 'learners', $str('addmany'));
    $body = html_writer::div($str('addmany_summary', ['added' => $added, 'failed' => $failed]),
        'alert ' . ($failed ? 'alert-warning' : 'alert-success'));
    $body .= html_writer::div($str('signin_where') . ' ' . html_writer::tag('code', s($signinurl)), 'mb-2');
    $body .= html_writer::div(html_writer::table($table), 'cport-table-wrap');
    $body .= html_writer::tag('button', $str('addmany_download'), ['type' => 'button', 'class' => 'btn btn-primary',
        'data-cport-download' => clean_filename(format_string($scope->client->name) . '-sign-in.csv')]) . ' ';
    $body .= html_writer::tag('button', $str('signin_print'), ['type' => 'button', 'class' => 'btn btn-outline-secondary',
        'onclick' => 'window.print()']) . ' ';
    $body .= html_writer::link($listurl, $str('nav_learners'), ['class' => 'btn btn-outline-secondary']);
    $body .= html_writer::div($str('signin_once_many'), 'cport-muted mt-2');
    echo ui::card('', html_writer::div($body, 'cport-pad'));
    echo ui::footer();
    exit;
}

$switch = $bulk
    ? html_writer::link($scope->url('/local/clientportal/addlearner.php'), $str('addlearner_one'),
        ['class' => 'btn btn-outline-secondary'])
    : html_writer::link($scope->url('/local/clientportal/addlearner.php', ['bulk' => 1]), $str('addmany'),
        ['class' => 'btn btn-outline-secondary']);
echo ui::header($scope, 'learners', $str($bulk ? 'addmany' : 'addlearner'), $switch);
echo ui::card('', html_writer::div(
    html_writer::div($str($bulk ? 'addmany_intro' : 'addlearner_intro'), 'cport-muted mb-3') . $form->render(),
    'cport-pad'
));
echo ui::footer();
