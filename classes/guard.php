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

namespace local_clientportal;

use moodle_url;

/**
 * Keeps client staff inside the portal.
 *
 * Client staff hold no course enrolments and only the portal role, so Moodle already refuses them
 * course and learner data. This guard is the second layer: it sends them to the portal from every
 * other page, so they never land on the dashboard, course lists, profiles or messaging.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guard {
    /** @var array userid => bool */
    private static array $cache = [];

    /** Pages client staff may open, by path prefix (relative to wwwroot). */
    private const ALLOWED = [
        '/local/clientportal/',
        '/login/',
        '/auth/',
        '/admin/tool/policy/',
        '/admin/tool/mfa/',
        '/user/policy.php',
        '/user/contactsitesupport.php',
        // Theme and library assets only: never theme or plugin pages (for example /theme/lmslabs/people.php).
        '/theme/styles.php',
        '/theme/styles_debug.php',
        '/theme/image.php',
        '/theme/font.php',
        '/theme/javascript.php',
        '/theme/jquery.php',
        '/theme/yui_combo.php',
        '/theme/yui_image.php',
        '/lib/javascript.php',
        '/lib/requirejs.php',
        '/lib/ajax/',
        '/pluginfile.php',
        '/local/cohortbranding/cohortcss.php',
        '/help.php',
        '/help_ajax.php',
    ];

    /**
     * Is this user client staff (and so locked to the portal)? Site admins never are.
     *
     * @param int|null $userid defaults to the current user
     * @return bool
     */
    public static function is_portal_user(?int $userid = null): bool {
        global $DB, $USER;
        $userid = $userid ?? (int)($USER->id ?? 0);
        if ($userid <= 0) {
            return false;
        }
        if (!array_key_exists($userid, self::$cache)) {
            self::$cache[$userid] = !is_siteadmin($userid) &&
                $DB->record_exists('local_clientportal_member', ['userid' => $userid]);
        }
        return self::$cache[$userid];
    }

    /**
     * Forget cached answers (after staff changes).
     *
     * @param int|null $userid
     */
    public static function reset_cache(?int $userid = null): void {
        if ($userid === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$userid]);
        }
    }

    /**
     * May client staff open this script?
     *
     * @param string $script path relative to wwwroot, e.g. /my/index.php
     * @return bool
     */
    public static function allowed(string $script): bool {
        // Client staff never need tokens or the site-admin client pages (they would see an "Access denied" page
        // in the full site layout there).
        if ($script === '/login/token.php' || strpos($script, '/local/clientportal/admin/') === 0) {
            return false;
        }
        $extra = preg_split('/\R/', (string)get_config('local_clientportal', 'extraallowed'), -1, PREG_SPLIT_NO_EMPTY);
        foreach (array_merge(self::ALLOWED, array_map('trim', $extra)) as $prefix) {
            if ($prefix !== '' && strpos($script, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Send client staff to the portal from any page outside it.
     *
     * @param \stdClass|int|null $courseorid the course require_login() was called with
     */
    public static function enforce($courseorid = null): void {
        global $SCRIPT, $PAGE;
        if (
            during_initial_install() || CLI_SCRIPT || AJAX_SCRIPT || (defined('WS_SERVER') && WS_SERVER) ||
                !isloggedin() || isguestuser()
        ) {
            return;
        }
        if (!self::is_portal_user()) {
            return;
        }
        $script = (string)($SCRIPT ?? '');
        if ($script === '' || self::allowed($script)) {
            return;
        }
        // The client's own courses: staff of that client may work in them (Moodle still checks their roles).
        $courseid = is_object($courseorid) ? (int)$courseorid->id : (int)$courseorid;
        if (!$courseid && isset($PAGE->course->id)) {
            $courseid = (int)$PAGE->course->id;
        }
        if ($courseid > SITEID && self::in_client_course($courseid)) {
            return;
        }
        redirect(new moodle_url('/local/clientportal/index.php'));
    }

    /**
     * Is the course one of the client's own, and the current user that client's staff?
     *
     * @param int $courseid
     * @return bool
     */
    public static function in_client_course(int $courseid): bool {
        global $DB, $USER;
        $owner = courses::owner($courseid);
        return $owner && $DB->record_exists('local_clientportal_member', ['clientid' => $owner, 'userid' => $USER->id]);
    }

    /**
     * Hook: a "back to your portal" bar for client staff working in their own courses.
     *
     * @param \core\hook\output\before_standard_top_of_body_html_generation $hook
     */
    public static function top_of_body(\core\hook\output\before_standard_top_of_body_html_generation $hook): void {
        global $PAGE;
        if (during_initial_install() || !isloggedin() || isguestuser() || !self::is_portal_user()) {
            return;
        }
        $courseid = (int)($PAGE->course->id ?? 0);
        if ($courseid <= SITEID || !self::in_client_course($courseid)) {
            return;
        }
        $client = manager::get_client(courses::owner($courseid));
        $link = \html_writer::link(
            new moodle_url('/local/clientportal/coursemanage.php', ['id' => $courseid]),
            get_string('backtoportal', 'local_clientportal', format_string($client->name)),
            ['class' => 'cport-backbar-link']
        );
        $hook->add_html(\html_writer::div($link, 'cport-backbar'));
    }

    /**
     * Hook: pages that never call require_login().
     *
     * @param \core\hook\output\before_http_headers $hook
     */
    public static function before_http_headers(\core\hook\output\before_http_headers $hook): void {
        self::enforce();
    }
}
