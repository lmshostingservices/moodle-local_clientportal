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
 * A client's own sign-in link: remembers the client, then opens the normal sign-in page with its branding.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// The sign-in page itself needs no login.
// phpcs:ignore moodle.Files.RequireLogin.Missing
require(__DIR__ . '/../../config.php');

$client = \local_clientportal\branding::client_from_param(optional_param('c', '', PARAM_ALPHANUMEXT));
\local_clientportal\branding::remember($client ? (int)$client->id : 0);
if (isloggedin() && !isguestuser()) {
    redirect(new moodle_url('/'));
}
redirect(new moodle_url('/login/index.php'));
