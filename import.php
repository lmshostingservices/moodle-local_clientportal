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
 * Client Portal - onboard many learners at once from a CSV: download your learners, fill in roles and pathways, upload.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\integration\matrix;
use local_clientportal\integration\pathways;
use local_clientportal\onboarding;
use local_clientportal\report;
use local_clientportal\scope;
use local_clientportal\ui;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

require_login(null, false);
// Access comes from client membership, checked by scope::require_portal(), so no require_capability() here.

$scope = scope::require_portal(new moodle_url('/local/clientportal/import.php'), true);
$hasmatrix = matrix::available();
$haspathways = pathways::available();
if (!$hasmatrix && !$haspathways) {
    redirect($scope->url('/local/clientportal/index.php'));
}
$pageurl = $scope->url('/local/clientportal/import.php');
$listurl = $scope->url('/local/clientportal/onboarding.php');

$report = new report($scope);
$learners = $report->learners(null, '', 'name', 0, 0);
$positions = $hasmatrix ? matrix::positions($scope) : [];
$departments = $hasmatrix ? matrix::departments($scope) : [];
$managers = $hasmatrix ? matrix::manager_options($scope) : [];
$allpathways = $haspathways ? pathways::all($scope) : [];
$columns = ['email', 'firstname', 'lastname'];
if ($hasmatrix) {
    $columns = array_merge($columns, ['position', 'startdate', 'manageremail', 'employeeid']);
}
if ($haspathways) {
    $columns = array_merge($columns, ['pathways', 'finishby']);
}

/**
 * Normalise text for matching names.
 *
 * @param string $text
 * @return string
 */
function local_clientportal_key(string $text): string {
    return core_text::strtolower(trim(preg_replace('/\s+/', ' ', $text)));
}

/**
 * Read a date written as 2026-11-30, 30/11/2026 or 30-11-2026.
 *
 * @param string $text
 * @return int|null|false null when blank, false when unreadable
 */
function local_clientportal_date(string $text) {
    $text = trim($text);
    if ($text === '') {
        return null;
    }
    if (preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})$~', $text, $m)) {
        [, $y, $mo, $d] = $m;
    } else if (preg_match('~^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$~', $text, $m)) {
        [, $d, $mo, $y] = $m;
    } else {
        return false;
    }
    if (!checkdate((int)$mo, (int)$d, (int)$y)) {
        return false;
    }
    return make_timestamp((int)$y, (int)$mo, (int)$d, 12);
}

// Template: every learner with what is already known, ready to fill in.
if (optional_param('template', 0, PARAM_BOOL)) {
    $staff = $hasmatrix ? matrix::staff($scope) : [];
    $onpaths = [];
    foreach ($haspathways ? pathways::assignments($scope) : [] as $a) {
        if ($a->status === 'active') {
            $onpaths[(int)$a->userid][] = format_string($a->pathway->name);
        }
    }
    $managermail = [];
    foreach (local_clientportal\manager::get_members($scope->id()) as $m) {
        $managermail[(int)$m->id] = $m->email;
    }
    $csv = new csv_export_writer();
    $csv->set_filename(clean_filename(format_string($scope->client->name) . '-onboarding'));
    $csv->add_data($columns);
    foreach ($learners as $u) {
        $s = $staff[(int)$u->id] ?? null;
        $line = [$u->email, $u->firstname, $u->lastname];
        if ($hasmatrix) {
            $line[] = $s && isset($positions[$s->positionid]) ? format_string($positions[$s->positionid]->name) : '';
            $line[] = $s && $s->startdate ? userdate($s->startdate, '%Y-%m-%d') : '';
            $line[] = $s ? ($managermail[(int)($s->managerid ?? 0)] ?? '') : '';
            $line[] = $s->employeeid ?? '';
        }
        if ($haspathways) {
            $line[] = implode('; ', $onpaths[(int)$u->id] ?? []);
            $line[] = '';
        }
        $csv->add_data($line);
    }
    $csv->download_file();
    exit;
}

// Import the checked rows.
if (optional_param('confirm', '', PARAM_ALPHANUM) && confirm_sesskey()) {
    $key = required_param('confirm', PARAM_ALPHANUM);
    $rows = $SESSION->local_clientportal_import[$key] ?? null;
    unset($SESSION->local_clientportal_import[$key]);
    if (!$rows || (int)$rows['client'] !== $scope->id()) {
        redirect($pageurl, get_string('import_expired', 'local_clientportal'), null, \core\output\notification::NOTIFY_WARNING);
    }
    $enrol = optional_param('enrol', 0, PARAM_BOOL);
    $usual = optional_param('usual', 0, PARAM_BOOL);
    $total = ['enrolled' => 0, 'blocked' => [], 'pathways' => [], 'role' => false];
    $count = 0;
    foreach ($rows['rows'] as $r) {
        $result = onboarding::apply($scope, (int)$r['userid'], $r['opts'] + ['enrol' => $enrol, 'usual' => $usual]);
        $count++;
        $total['enrolled'] += $result['enrolled'];
        $total['blocked'] = array_unique(array_merge($total['blocked'], $result['blocked']));
        $total['pathways'] = array_unique(array_merge($total['pathways'], $result['pathways']));
    }
    local_clientportal\manager::log($scope->id(), 'onboarding_imported', null, (string)$count);
    [$parts, $warn] = onboarding::messages($total);
    array_unshift($parts, get_string('import_done', 'local_clientportal', $count));
    redirect($listurl, implode(' ', $parts), null, $warn ? \core\output\notification::NOTIFY_WARNING
        : \core\output\notification::NOTIFY_SUCCESS);
}

$form = new local_clientportal\form\import_form($pageurl);
$form->set_data(['client' => $scope->support ? $scope->id() : 0]);
$preview = '';
if ($data = $form->get_data()) {
    $content = $form->get_file_content('csvfile');
    $iid = csv_import_reader::get_new_iid('local_clientportal');
    $reader = new csv_import_reader($iid, 'local_clientportal');
    $reader->load_csv_content($content, 'utf-8', 'comma');
    $head = array_map(fn($h) => preg_replace('/[^a-z]/', '', core_text::strtolower((string)$h)), $reader->get_columns() ?: []);
    $col = array_flip($head);
    $byemail = [];
    $byusername = [];
    foreach ($learners as $u) {
        $byemail[local_clientportal_key($u->email)] = $u;
    }
    $posbyname = [];
    foreach ($positions as $p) {
        $posbyname[local_clientportal_key(format_string($p->name))] = (int)$p->id;
        if (isset($departments[$p->departmentid])) {
            $posbyname[local_clientportal_key($departments[$p->departmentid] . ': ' . format_string($p->name))] = (int)$p->id;
        }
    }
    $mgrbyemail = [];
    foreach (local_clientportal\manager::get_members($scope->id()) as $m) {
        $mgrbyemail[local_clientportal_key($m->email)] = (int)$m->id;
    }
    $pwbyname = [];
    foreach ($allpathways as $p) {
        $pwbyname[local_clientportal_key(format_string($p->name))] = (int)$p->id;
    }
    $staff = $hasmatrix ? matrix::staff($scope) : [];
    $onpaths = [];
    foreach ($haspathways ? pathways::assignments($scope) : [] as $a) {
        if ($a->status === 'active') {
            $onpaths[(int)$a->userid][(int)$a->pathwayid] = true;
        }
    }
    $cell = fn(array $line, string $name) => isset($col[$name]) ? trim((string)($line[$col[$name]] ?? '')) : '';
    $good = [];
    $table = new html_table();
    $table->attributes['class'] = 'table cport-table';
    $table->head = [get_string('import_line', 'local_clientportal'), get_string('learner', 'local_clientportal'),
        get_string('import_changes', 'local_clientportal'), get_string('status', 'local_clientportal')];
    $counts = ['ready' => 0, 'same' => 0, 'error' => 0];
    if (!isset($col['email'])) {
        $preview = html_writer::div(get_string('import_noemail', 'local_clientportal'), 'alert alert-warning');
    } else {
        $reader->init();
        $n = 1;
        while ($line = $reader->next()) {
            $n++;
            $errors = [];
            $changes = [];
            $opts = ['pathways' => []];
            $email = $cell($line, 'email');
            $u = $byemail[local_clientportal_key($email)] ?? null;
            if (!$u) {
                $errors[] = get_string('import_nolearner', 'local_clientportal', s($email));
            } else {
                $s = $staff[(int)$u->id] ?? null;
                if ($hasmatrix) {
                    $pos = $cell($line, 'position');
                    if ($pos !== '') {
                        $pid = $posbyname[local_clientportal_key($pos)] ?? 0;
                        if (!$pid) {
                            $errors[] = get_string('import_noposition', 'local_clientportal', s($pos));
                        } else if (!$s || (int)$s->positionid !== $pid) {
                            $changes[] = get_string('position', 'local_clientportal') . ': ' .
                                s(format_string($positions[$pid]->name));
                            $opts['positionid'] = $pid;
                        } else {
                            $opts['positionid'] = $pid;
                        }
                    }
                    $start = local_clientportal_date($cell($line, 'startdate'));
                    if ($start === false) {
                        $errors[] = get_string('import_baddate', 'local_clientportal', s($cell($line, 'startdate')));
                    } else if ($start && (!$s || abs((int)$s->startdate - $start) > DAYSECS)) {
                        $changes[] = get_string('startdate', 'local_clientportal') . ': ' . userdate(
                            $start,
                            get_string('strftimedate', 'langconfig')
                        );
                        $opts['startdate'] = $start;
                    }
                    $memail = $cell($line, 'manageremail');
                    if ($memail !== '') {
                        $mid = $mgrbyemail[local_clientportal_key($memail)] ?? 0;
                        if (!$mid) {
                            $errors[] = get_string('import_nomanager', 'local_clientportal', s($memail));
                        } else if (!$s || (int)($s->managerid ?? 0) !== $mid) {
                            $changes[] = get_string('directmanager', 'local_clientportal') . ': ' . s($managers[$mid] ?? '');
                            $opts['managerid'] = $mid;
                        }
                    }
                    $empid = $cell($line, 'employeeid');
                    if ($empid !== '' && (!$s || (string)($s->employeeid ?? '') !== $empid)) {
                        $changes[] = get_string('employeeid', 'local_clientportal') . ': ' . s($empid);
                        $opts['employeeid'] = clean_param($empid, PARAM_NOTAGS);
                    }
                    if (!empty($opts['startdate']) || !empty($opts['managerid']) || isset($opts['employeeid'])) {
                        if (empty($opts['positionid'])) {
                            if ($s) {
                                $opts['positionid'] = (int)$s->positionid;
                            } else {
                                $errors[] = get_string('import_needposition', 'local_clientportal');
                            }
                        }
                    }
                    if (!empty($opts['positionid']) && $s && !$changes && (int)$s->positionid === (int)$opts['positionid']) {
                        unset($opts['positionid']);
                    }
                }
                if ($haspathways) {
                    foreach (preg_split('/[;|]/', $cell($line, 'pathways')) as $name) {
                        if (trim($name) === '') {
                            continue;
                        }
                        $pid = $pwbyname[local_clientportal_key($name)] ?? 0;
                        if (!$pid) {
                            $errors[] = get_string('import_nopathway', 'local_clientportal', s(trim($name)));
                        } else if (!isset($onpaths[(int)$u->id][$pid])) {
                            $opts['pathways'][] = $pid;
                            $changes[] = get_string('pathway', 'local_clientportal') . ': ' .
                                s(format_string($allpathways[$pid]->name));
                        }
                    }
                    $finish = local_clientportal_date($cell($line, 'finishby'));
                    if ($finish === false) {
                        $errors[] = get_string('import_baddate', 'local_clientportal', s($cell($line, 'finishby')));
                    } else if ($finish) {
                        $opts['targetdate'] = $finish;
                    }
                }
            }
            if ($errors) {
                $status = html_writer::span(get_string('import_skip', 'local_clientportal'), 'cport-pill cport-pill-late');
                $what = html_writer::div(implode('<br>', $errors), 'cport-import-error');
                $counts['error']++;
            } else if (!$changes) {
                $status = html_writer::span(
                    get_string('import_nochange', 'local_clientportal'),
                    'cport-pill cport-pill-notstarted'
                );
                $what = html_writer::span('–', 'text-muted');
                $counts['same']++;
            } else {
                $status = html_writer::span(get_string('import_ready', 'local_clientportal'), 'cport-pill cport-pill-completed');
                $what = implode('<br>', $changes);
                $good[] = ['userid' => (int)$u->id, 'opts' => $opts];
                $counts['ready']++;
            }
            $table->data[] = [$n, $u ? fullname($u) : s($email), $what, $status];
        }
    }
    $reader->cleanup(true);
    if ($table->data) {
        $key = random_string(12);
        $SESSION->local_clientportal_import = [$key => ['client' => $scope->id(), 'rows' => $good]];
        $summary = html_writer::div(
            ui::stat(get_string('import_ready', 'local_clientportal'), $counts['ready'], '', 'good') .
            ui::stat(get_string('import_nochange', 'local_clientportal'), $counts['same']) .
            ui::stat(get_string('import_skip', 'local_clientportal'), $counts['error'], '', $counts['error'] ? 'warn' : 'neutral'),
            'cport-stats'
        );
        $confirm = '';
        if ($good) {
            $confirm = html_writer::start_tag(
                'form',
                ['method' => 'post', 'action' => $pageurl->out(false), 'class' => 'cport-pad']
            );
            $confirm .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $confirm .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirm', 'value' => $key]);
            if (local_clientportal\integration\enrolment::allowed()) {
                $confirm .= html_writer::div(
                    html_writer::checkbox('enrol', 1, true, get_string('enrolrequired', 'local_clientportal')),
                    'mb-2'
                );
            }
            if ($hasmatrix && $haspathways) {
                $confirm .= html_writer::div(html_writer::checkbox(
                    'usual',
                    1,
                    true,
                    get_string('usualpathways', 'local_clientportal')
                ), 'mb-3');
            }
            $confirm .= html_writer::tag(
                'button',
                get_string('import_go', 'local_clientportal', $counts['ready']),
                ['type' => 'submit', 'class' => 'btn btn-primary']
            );
            $confirm .= html_writer::end_tag('form');
        }
        $preview = $summary . ui::card(
            get_string('import_preview', 'local_clientportal'),
            $confirm . html_writer::div(html_writer::table($table), 'cport-table-wrap')
        );
    } else if ($preview === '') {
        $preview = html_writer::div(get_string('import_empty', 'local_clientportal'), 'alert alert-warning');
    }
}

$back = html_writer::link($listurl, get_string('nav_onboarding', 'local_clientportal'), ['class' => 'btn btn-outline-secondary']);
echo ui::header($scope, 'onboarding', get_string('import_title', 'local_clientportal'), $back);
if ($preview !== '') {
    echo $preview;
}
$steps = html_writer::tag('ol', html_writer::tag('li', get_string('import_step1', 'local_clientportal') . ' ' .
        html_writer::link(
            $scope->url('/local/clientportal/import.php', ['template' => 1]),
            get_string('import_template', 'local_clientportal'),
            ['class' => 'btn btn-sm btn-outline-primary ms-1 ml-1']
        )) .
    html_writer::tag('li', get_string('import_step2', 'local_clientportal', implode(', ', $columns))) .
    html_writer::tag('li', get_string('import_step3', 'local_clientportal')), ['class' => 'cport-steps']);
echo ui::card(get_string('import_how', 'local_clientportal'), html_writer::div($steps . $form->render(), 'cport-pad cport-form'));
echo ui::footer();
