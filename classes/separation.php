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

use context_system;

/**
 * Site settings that keep clients apart: one place to see them and switch them all on.
 *
 * Clients share one Moodle site. Their learners only see courses they are enrolled in, and the portal only shows a
 * client its own people; these settings close the site-wide pages where one client's people or courses could
 * otherwise appear to another (messaging, search, the course list, blogs, tags, the site home page, guest access).
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class separation {
    /** Capabilities the "Authenticated user" and "Guest" roles must not have at site level. */
    protected const ROLE_CAPS = [
        'moodle/category:viewcourselist',
        'moodle/user:viewdetails',
        'moodle/site:viewparticipants',
        'moodle/user:viewalldetails',
    ];

    /** Settings: name => [wanted value, plugin or null]. */
    protected const SETTINGS = [
        'messaging' => [0, null],
        'enableglobalsearch' => [0, null],
        'forcelogin' => [1, null],
        'guestloginbutton' => [0, null],
        'enableblogs' => [0, null],
        'usetags' => [0, null],
        'enablecourserequests' => [0, null],
        'frontpage' => ['', null],
        'frontpageloggedin' => ['', null],
        'defaulthomepage' => [1, null],
        'enablerssfeeds' => [0, null],
    ];

    /**
     * Every check with its state.
     *
     * @return \stdClass[] key, name, desc, ok, current
     */
    public static function checks(): array {
        global $CFG, $DB;
        $out = [];
        foreach (self::SETTINGS as $name => [$want]) {
            $current = $CFG->$name ?? null;
            $ok = (string)$current === (string)$want;
            $out[$name] = (object)[
                'key' => $name,
                'name' => get_string('sep_' . $name, 'local_clientportal'),
                'desc' => get_string('sep_' . $name . '_desc', 'local_clientportal'),
                'ok' => $ok,
            ];
        }
        $roles = self::open_roles();
        $out['rolecaps'] = (object)[
            'key' => 'rolecaps',
            'name' => get_string('sep_rolecaps', 'local_clientportal'),
            'desc' => get_string('sep_rolecaps_desc', 'local_clientportal'),
            'ok' => !$roles,
        ];
        $online = $DB->get_field('block', 'visible', ['name' => 'online_users']);
        $out['onlineusers'] = (object)[
            'key' => 'onlineusers',
            'name' => get_string('sep_onlineusers', 'local_clientportal'),
            'desc' => get_string('sep_onlineusers_desc', 'local_clientportal'),
            'ok' => $online === false || (int)$online === 0,
        ];
        return $out;
    }

    /**
     * Site-level roles for everyone (authenticated user, guest) that still have a capability they should not.
     *
     * @return array roleid => capability[]
     */
    protected static function open_roles(): array {
        global $CFG, $DB;
        $system = context_system::instance();
        $out = [];
        foreach (array_filter([(int)($CFG->defaultuserroleid ?? 0), (int)($CFG->guestroleid ?? 0)]) as $roleid) {
            foreach (self::ROLE_CAPS as $cap) {
                if (
                    $DB->record_exists('role_capabilities', ['roleid' => $roleid, 'contextid' => $system->id,
                        'capability' => $cap, 'permission' => CAP_ALLOW])
                ) {
                    $out[$roleid][] = $cap;
                }
            }
        }
        return $out;
    }

    /**
     * Put one check (or all of them) right.
     *
     * @param string|null $key null = all
     * @return int number changed
     */
    public static function fix(?string $key = null): int {
        global $DB;
        $changed = 0;
        foreach (self::checks() as $check) {
            if ($check->ok || ($key !== null && $key !== $check->key)) {
                continue;
            }
            if (isset(self::SETTINGS[$check->key])) {
                set_config($check->key, self::SETTINGS[$check->key][0]);
            } else if ($check->key === 'rolecaps') {
                $system = context_system::instance();
                foreach (self::open_roles() as $roleid => $caps) {
                    foreach ($caps as $cap) {
                        unassign_capability($cap, $roleid, $system->id);
                    }
                }
                // Client course managers still need to list their own courses.
                courses::ensure_roles();
            } else if ($check->key === 'onlineusers') {
                $DB->set_field('block', 'visible', 0, ['name' => 'online_users']);
            }
            $changed++;
        }
        if ($changed) {
            purge_caches(['muc' => true, 'theme' => false, 'lang' => false, 'js' => false]);
        }
        return $changed;
    }
}
