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
 * Client Portal - add or edit a client (site administration).
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\form\client_form;
use local_clientportal\manager;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = optional_param('id', 0, PARAM_INT);
admin_externalpage_setup('local_clientportal_clients', '', null, new moodle_url(
    '/local/clientportal/admin/client.php',
    $id ? ['id' => $id] : []
));
require_capability('local/clientportal:manageclients', context_system::instance());
$listurl = new moodle_url('/local/clientportal/admin/clients.php');

$client = $id ? manager::get_client($id, MUST_EXIST) : null;
$form = new client_form(null, ['id' => $id]);
if ($form->is_cancelled()) {
    redirect($listurl);
}
if ($data = $form->get_data()) {
    $newid = manager::save_client($data);
    $next = $id ? $listurl : new moodle_url('/local/clientportal/admin/members.php', ['client' => $newid]);
    redirect(
        $next,
        get_string('clientsaved', 'local_clientportal', format_string($data->name)),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}
if ($client) {
    $defaults = (array)$client;
    $defaults['cohortname'] = format_string($DB->get_field('cohort', 'name', ['id' => $client->cohortid]));
    $form->set_data($defaults);
}
echo $OUTPUT->header();
echo $OUTPUT->heading($client ? format_string($client->name) : get_string('addclient', 'local_clientportal'));
$form->display();
echo $OUTPUT->footer();
