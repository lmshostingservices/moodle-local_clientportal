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
 * Client Portal - event observers.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\user_enrolment_created',
        'callback' => '\local_clientportal\observer::user_enrolment_created',
    ],
    [
        'eventname' => '\core\event\cohort_member_removed',
        'callback' => '\local_clientportal\observer::cohort_member_removed',
    ],
    [
        'eventname' => '\core\event\cohort_deleted',
        'callback' => '\local_clientportal\observer::cohort_deleted',
    ],
    [
        'eventname' => '\core\event\user_deleted',
        'callback' => '\local_clientportal\observer::user_deleted',
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback' => '\local_clientportal\observer::course_deleted',
    ],
];
