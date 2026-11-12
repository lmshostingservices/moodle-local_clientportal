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
 * Client Portal - download one certificate.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\certificates;
use local_clientportal\manager;
use local_clientportal\scope;

require(__DIR__ . '/../../config.php');

require_login(null, false);
// Access comes from client membership, checked by scope::require_portal(), so no require_capability() here.

$userid = required_param('user', PARAM_INT);
$type = required_param('type', PARAM_ALPHA);
$id = required_param('id', PARAM_INT);
$scope = scope::require_portal(new moodle_url(
    '/local/clientportal/certificate.php',
    ['user' => $userid, 'type' => $type, 'id' => $id]
));

[$filename, $content] = certificates::pdf($scope, $userid, $type, $id);
manager::log($scope->id(), 'certificate_downloaded', $userid, $filename);
send_file($content, $filename, 0, 0, true, true, 'application/pdf');
