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
 * Client Portal - all issued certificates, with a bulk ZIP download.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\certificates;
use local_clientportal\manager;
use local_clientportal\report;
use local_clientportal\scope;
use local_clientportal\ui;

require(__DIR__ . '/../../config.php');

require_login(null, false);
// Access comes from client membership, checked by scope::require_portal(), so no require_capability() here.

$groupid = optional_param('group', 0, PARAM_INT);
$zip = optional_param('zip', 0, PARAM_BOOL);
$scope = scope::require_portal(new moodle_url('/local/clientportal/certificates.php', $groupid ? ['group' => $groupid] : []));
if ($groupid) {
    manager::get_group($scope->id(), $groupid);
}
$items = certificates::issued_for_client($scope, $groupid ?: null);
$report = new report($scope);
$report->add_names($items);
usort($items, fn($a, $b) => [$a->fullname, $a->name] <=> [$b->fullname, $b->name]);

if ($zip) {
    require_sesskey();
    if (!$items) {
        redirect($scope->url('/local/clientportal/certificates.php'), get_string('nocertificates', 'local_clientportal'));
    }
    core_php_time_limit::raise(600);
    raise_memory_limit(MEMORY_EXTRA);
    $files = [];
    $used = [];
    foreach ($items as $item) {
        [$name, $content] = certificates::pdf($scope, $item->userid, $item->type, $item->id);
        $base = $name;
        $n = 2;
        while (isset($used[$name])) {
            $name = preg_replace('/\.pdf$/', '', $base) . " ($n).pdf";
            $n++;
        }
        $used[$name] = true;
        $files[$name] = [$content];
    }
    $tempzip = tempnam(make_request_directory(), 'zip');
    $packer = get_file_packer('application/zip');
    $packer->archive_to_pathname($files, $tempzip);
    manager::log($scope->id(), 'certificates_zipped', null, (string)count($files));
    $filename = clean_filename(format_string($scope->client->name) . ' certificates ' . userdate(time(), '%Y-%m-%d') . '.zip');
    send_temp_file($tempzip, $filename);
}

$actions = '';
if ($items) {
    $actions = html_writer::link(
        $scope->url(
            '/local/clientportal/certificates.php',
            ['zip' => 1, 'sesskey' => sesskey()] + ($groupid ? ['group' => $groupid] : [])
        ),
        get_string('downloadallzip', 'local_clientportal', count($items)),
        ['class' => 'btn btn-primary']
    );
}
echo ui::header($scope, 'certificates', get_string('nav_certificates', 'local_clientportal'), $actions);
$body = html_writer::div(ui::filters($scope, $scope->url('/local/clientportal/certificates.php'), $groupid), 'cport-toolbar');
if (!$items) {
    echo ui::card('', $body . ui::empty(get_string('nocertificates', 'local_clientportal')));
    echo ui::footer();
    exit;
}
$coursenames = $report->courses();
$table = new html_table();
$table->attributes['class'] = 'table cport-table';
$table->head = [get_string('learner', 'local_clientportal'), get_string('certificate', 'local_clientportal'), get_string('course'),
    get_string('issued', 'local_clientportal'), get_string('expires', 'local_clientportal'), ''];
foreach ($items as $c) {
    $table->data[] = [
        html_writer::link(
            $scope->url('/local/clientportal/learner.php', ['id' => $c->userid]),
            s($c->fullname),
            ['class' => 'cport-name']
        ),
        s($c->name),
        s($coursenames[$c->courseid] ?? ''),
        ui::date($c->issued),
        ui::date($c->expires ?? null),
        html_writer::link($scope->url('/local/clientportal/certificate.php', ['user' => $c->userid, 'type' => $c->type,
            'id' => $c->id]), get_string('download'), ['class' => 'btn btn-sm btn-outline-primary']),
    ];
}
$body .= html_writer::div(html_writer::table($table), 'cport-table-wrap');
echo ui::card('', $body);
echo ui::footer();
