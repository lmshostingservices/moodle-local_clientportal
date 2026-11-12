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
 * Client Portal - library callbacks.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Keep client staff inside the portal. Called by require_login() on every page that uses it
 * (Moodle 4.5 to 5.1 still use this lib.php callback; there is no hook for it).
 *
 * @param stdClass|int|null $courseorid
 * @param bool $autologinguest
 * @param cm_info|stdClass|null $cm
 * @param bool $setwantsurltome
 * @param bool $preventredirect
 */
function local_clientportal_after_require_login(
    $courseorid = null,
    $autologinguest = null,
    $cm = null,
    $setwantsurltome = null,
    $preventredirect = null
): void {
    if ($preventredirect) {
        return;
    }
    \local_clientportal\guard::enforce();
}
