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
 * Client Portal - hook callbacks.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        // Catches pages that never call require_login() (for example the front page without forcelogin).
        'hook' => \core\hook\output\before_http_headers::class,
        'callback' => [\local_clientportal\guard::class, 'before_http_headers'],
        'priority' => 1000,
    ],
    [
        // A "back to your portal" bar when client staff work in their own courses.
        'hook' => \core\hook\output\before_standard_top_of_body_html_generation::class,
        'callback' => [\local_clientportal\guard::class, 'top_of_body'],
    ],
];
