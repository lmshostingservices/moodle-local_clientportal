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

use context_course;
use stdClass;

/**
 * Shared courses: courses (not owned by a client) where more than one client owns enrolments.
 *
 * Portal reports are always scoped by owned enrolments and never by groups. This class only decides what
 * learners see of each other inside a shared course: each client gets its own Moodle group (idnumber
 * cport_client_{clientid}), its learners are kept in it, and the course is put in separate (or visible) groups
 * mode. The course author's settings are saved first, so undo puts them back exactly.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class shared {
    /** Group idnumber prefix; the client id follows. */
    public const GROUP_PREFIX = 'cport_client_';
    /** Grouping idnumber holding the client groups. */
    public const GROUPING = 'cport_clients';
    /** Setting value: do nothing. */
    public const MODE_OFF = 'off';
    /** Setting value: list shared courses with a one-click set-up. */
    public const MODE_SUGGEST = 'suggest';
    /** Setting value: set up shared courses as soon as a second client has learners in them. */
    public const MODE_AUTO = 'auto';

    /**
     * The site setting.
     *
     * @return string off|suggest|auto
     */
    public static function mode(): string {
        $mode = (string)get_config('local_clientportal', 'sharedcourses');
        return in_array($mode, [self::MODE_OFF, self::MODE_SUGGEST, self::MODE_AUTO], true) ? $mode : self::MODE_SUGGEST;
    }

    /**
     * Default options for a new set-up, from the settings.
     *
     * @return array groupmode, applyactivities, locked
     */
    public static function defaults(): array {
        $groupmode = (int)get_config('local_clientportal', 'sharedgroupmode');
        $apply = get_config('local_clientportal', 'sharedapplyactivities');
        return [
            'groupmode' => $groupmode === VISIBLEGROUPS ? VISIBLEGROUPS : SEPARATEGROUPS,
            'applyactivities' => $apply === false ? true : (bool)$apply,
            'locked' => (bool)get_config('local_clientportal', 'sharedlock'),
        ];
    }

    /**
     * Clients with owned enrolments in a course, with counts.
     *
     * @param int $courseid
     * @return array clientid => learner count
     */
    public static function clients_in(int $courseid): array {
        global $DB;
        $rows = $DB->get_records_sql_menu(
            "SELECT clientid, COUNT(1) FROM {local_clientportal_enrol} WHERE courseid = ? GROUP BY clientid",
            [$courseid]
        );
        return array_map('intval', $rows);
    }

    /**
     * Is this a shared course: not a client's own course, with owned enrolments from two or more clients?
     *
     * @param int $courseid
     * @return bool
     */
    public static function is_shared(int $courseid): bool {
        return $courseid > SITEID && courses::owner($courseid) === 0 && count(self::clients_in($courseid)) >= 2;
    }

    /**
     * The set-up record of a course, if it has been set up.
     *
     * @param int $courseid
     * @return stdClass|null
     */
    public static function get(int $courseid): ?stdClass {
        global $DB;
        return $DB->get_record('local_clientportal_shared', ['courseid' => $courseid]) ?: null;
    }

    /**
     * Shared courses and courses already set up, for the admin page.
     *
     * @return stdClass[] courseid => course with ->clients (clientid => count), ->setup (record or null), ->status
     */
    public static function overview(): array {
        global $DB;
        $ids = $DB->get_fieldset_sql(
            "SELECT courseid FROM {local_clientportal_enrol} GROUP BY courseid HAVING COUNT(DISTINCT clientid) >= 2"
        );
        $ids = array_merge(array_map('intval', $ids), array_map('intval', $DB->get_fieldset_select(
            'local_clientportal_shared',
            'courseid',
            '1 = 1'
        )));
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($ids);
        $fields = 'id, fullname, shortname, groupmode, groupmodeforce';
        $courses = $DB->get_records_select('course', "id $in", $params, 'fullname', $fields);
        $out = [];
        foreach ($courses as $course) {
            $setup = self::get((int)$course->id);
            if (!$setup && courses::owner((int)$course->id)) {
                continue;
            }
            $course->clients = self::clients_in((int)$course->id);
            $course->setup = $setup;
            $course->issues = $setup ? self::issues((int)$course->id) : [];
            $course->status = !$setup ? 'notsetup' : ($course->issues ? 'attention' : ($setup->locked ? 'locked' : 'apart'));
            $out[(int)$course->id] = $course;
        }
        return $out;
    }

    /**
     * Shared courses that are not set up yet.
     *
     * @return int[] course ids
     */
    public static function not_set_up(): array {
        return array_keys(array_filter(self::overview(), fn($c) => !$c->setup && count($c->clients) >= 2));
    }

    /**
     * What setting up would change, so the admin sees it before confirming.
     *
     * @param int $courseid
     * @param int $groupmode
     * @return stdClass ->course, ->activities (number that support groups), ->different (activities whose
     *                  group mode would change), ->clients (clientid => count)
     */
    public static function preview(int $courseid, int $groupmode): stdClass {
        $course = get_course($courseid);
        $activities = 0;
        $different = [];
        foreach (self::group_activities($courseid) as $cm) {
            $activities++;
            if ((int)$cm->groupmode !== $groupmode) {
                $different[] = $cm;
            }
        }
        return (object)['course' => $course, 'activities' => $activities, 'different' => $different,
            'clients' => self::clients_in($courseid)];
    }

    /**
     * Activities in the course that support groups.
     *
     * @param int $courseid
     * @return \cm_info[]
     */
    protected static function group_activities(int $courseid): array {
        $out = [];
        foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
            if (!$cm->deletioninprogress && plugin_supports('mod', $cm->modname, FEATURE_GROUPS, false)) {
                $out[(int)$cm->id] = $cm;
            }
        }
        return $out;
    }

    /**
     * Keep clients apart in a course.
     *
     * @param int $courseid
     * @param int $groupmode SEPARATEGROUPS or VISIBLEGROUPS
     * @param bool $applyactivities also set existing activities that support groups
     * @param bool $locked force the course group mode on every activity
     * @return stdClass the set-up record
     */
    public static function setup(int $courseid, int $groupmode, bool $applyactivities, bool $locked): stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        if (courses::owner($courseid)) {
            throw new \moodle_exception('shared_owncourse', 'local_clientportal');
        }
        $groupmode = $groupmode === VISIBLEGROUPS ? VISIBLEGROUPS : SEPARATEGROUPS;
        $course = $DB->get_record('course', ['id' => $courseid], 'id, groupmode, groupmodeforce', MUST_EXIST);
        $transaction = $DB->start_delegated_transaction();
        $record = self::get($courseid);
        $now = time();
        if (!$record) {
            $record = (object)[
                'courseid' => $courseid,
                'prevgroupmode' => (int)$course->groupmode,
                'prevgroupmodeforce' => (int)$course->groupmodeforce,
                'timecreated' => $now,
            ];
        }
        $record->groupmode = $groupmode;
        $record->locked = $locked ? 1 : 0;
        $record->applyactivities = $applyactivities ? 1 : 0;
        $record->timemodified = $now;
        if (empty($record->id)) {
            $record->id = $DB->insert_record('local_clientportal_shared', $record);
        } else {
            $DB->update_record('local_clientportal_shared', $record);
        }

        $DB->update_record('course', (object)['id' => $courseid, 'groupmode' => $groupmode,
            'groupmodeforce' => $locked ? 1 : 0, 'timemodified' => $now]);
        if ($applyactivities) {
            foreach (self::group_activities($courseid) as $cm) {
                if ((int)$cm->groupmode === $groupmode) {
                    continue;
                }
                if (!$DB->record_exists('local_clientportal_sharedcm', ['sharedid' => $record->id, 'cmid' => $cm->id])) {
                    $DB->insert_record('local_clientportal_sharedcm', (object)['sharedid' => $record->id, 'cmid' => $cm->id,
                        'prevgroupmode' => (int)$cm->groupmode]);
                }
                self::set_cm_groupmode($courseid, (int)$cm->id, $groupmode);
            }
        }
        rebuild_course_cache($courseid, true);
        $transaction->allow_commit();

        self::sync_course($courseid);
        foreach (array_keys(self::clients_in($courseid)) as $clientid) {
            manager::log((int)$clientid, 'shared_setup', null, self::course_name($courseid));
        }
        return $record;
    }

    /**
     * Put a course back the way it was.
     *
     * @param int $courseid
     * @param bool $removegroups delete the client groups too
     */
    public static function undo(int $courseid, bool $removegroups): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/group/lib.php');
        $record = self::get($courseid);
        if (!$record) {
            return;
        }
        $transaction = $DB->start_delegated_transaction();
        $DB->update_record('course', (object)['id' => $courseid, 'groupmode' => (int)$record->prevgroupmode,
            'groupmodeforce' => (int)$record->prevgroupmodeforce, 'timemodified' => time()]);
        foreach ($DB->get_records('local_clientportal_sharedcm', ['sharedid' => $record->id]) as $saved) {
            if ($DB->record_exists('course_modules', ['id' => $saved->cmid, 'course' => $courseid])) {
                self::set_cm_groupmode($courseid, (int)$saved->cmid, (int)$saved->prevgroupmode);
            }
        }
        $DB->delete_records('local_clientportal_sharedcm', ['sharedid' => $record->id]);
        $DB->delete_records('local_clientportal_shared', ['id' => $record->id]);
        $transaction->allow_commit();
        if ($removegroups) {
            foreach (self::client_groups($courseid) as $group) {
                groups_delete_group($group);
            }
            if ($grouping = groups_get_grouping_by_idnumber($courseid, self::GROUPING)) {
                groups_delete_grouping($grouping);
            }
        }
        rebuild_course_cache($courseid, true);
        foreach (array_keys(self::clients_in($courseid)) as $clientid) {
            manager::log((int)$clientid, 'shared_undone', null, self::course_name($courseid));
        }
    }

    /**
     * Set an activity's group mode (Moodle 5.2 moved this to the course format's cmactions).
     *
     * @param int $courseid
     * @param int $cmid
     * @param int $groupmode
     */
    public static function set_cm_groupmode(int $courseid, int $cmid, int $groupmode): void {
        global $CFG;
        if (method_exists('\\core_courseformat\\local\\cmactions', 'set_groupmode')) {
            (new \core_courseformat\local\cmactions(get_course($courseid)))->set_groupmode($cmid, $groupmode);
            return;
        }
        require_once($CFG->dirroot . '/course/lib.php');
        set_coursemodule_groupmode($cmid, $groupmode);
    }

    /**
     * The client groups in a course, clientid => group.
     *
     * @param int $courseid
     * @return stdClass[]
     */
    public static function client_groups(int $courseid): array {
        global $DB;
        $groups = $DB->get_records_select(
            'groups',
            'courseid = :courseid AND ' . $DB->sql_like('idnumber', ':prefix'),
            ['courseid' => $courseid, 'prefix' => self::GROUP_PREFIX . '%']
        );
        $out = [];
        foreach ($groups as $group) {
            $clientid = (int)substr($group->idnumber, strlen(self::GROUP_PREFIX));
            if ($clientid) {
                $out[$clientid] = $group;
            }
        }
        return $out;
    }

    /**
     * The client's group in a course, created (with the "Clients" grouping) when missing.
     *
     * @param int $courseid
     * @param int $clientid
     * @return stdClass|null null when the client no longer exists
     */
    public static function ensure_group(int $courseid, int $clientid): ?stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');
        $client = manager::get_client($clientid);
        if (!$client) {
            return null;
        }
        $group = groups_get_group_by_idnumber($courseid, self::GROUP_PREFIX . $clientid);
        if (!$group) {
            $id = groups_create_group((object)[
                'courseid' => $courseid,
                'name' => format_string($client->name, true, ['context' => context_course::instance($courseid)]),
                'idnumber' => self::GROUP_PREFIX . $clientid,
                'description' => get_string('shared_groupdesc', 'local_clientportal'),
                'descriptionformat' => FORMAT_HTML,
            ]);
            $group = groups_get_group($id);
        }
        $grouping = groups_get_grouping_by_idnumber($courseid, self::GROUPING);
        $groupingid = $grouping ? (int)$grouping->id : groups_create_grouping((object)[
            'courseid' => $courseid,
            'name' => get_string('shared_grouping', 'local_clientportal'),
            'idnumber' => self::GROUPING,
            'description' => get_string('shared_groupdesc', 'local_clientportal'),
            'descriptionformat' => FORMAT_HTML,
        ]);
        groups_assign_grouping($groupingid, (int)$group->id);
        return $group;
    }

    /**
     * Bring a set-up course's client groups in line with who owns which enrolment.
     *
     * @param int $courseid
     * @return int memberships added or removed
     */
    public static function sync_course(int $courseid): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');
        if (!self::get($courseid)) {
            return 0;
        }
        $changes = 0;
        foreach (array_keys(self::clients_in($courseid)) as $clientid) {
            self::ensure_group($courseid, (int)$clientid);
        }
        $owners = $DB->get_records_menu('local_clientportal_enrol', ['courseid' => $courseid], '', 'userid, clientid');
        $context = context_course::instance($courseid);
        foreach (self::client_groups($courseid) as $clientid => $group) {
            $members = $DB->get_records_menu(
                'groups_members',
                ['groupid' => $group->id],
                '',
                'userid, component'
            );
            foreach ($owners as $userid => $owner) {
                if ((int)$owner === $clientid && !isset($members[$userid]) && is_enrolled($context, (int)$userid)) {
                    if (groups_add_member($group, (int)$userid, 'local_clientportal', $clientid)) {
                        $changes++;
                    }
                }
            }
            foreach ($members as $userid => $component) {
                if ($component === 'local_clientportal' && (int)($owners[$userid] ?? 0) !== $clientid) {
                    groups_remove_member($group, (int)$userid);
                    $changes++;
                }
            }
        }
        return $changes;
    }

    /**
     * One learner's ownership or enrolment changed in a course: put them in the right client group, and set
     * the course up when the site setting is Automatic and this made it shared.
     *
     * @param int $userid
     * @param int $courseid
     */
    public static function user_changed(int $userid, int $courseid): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');
        if ($courseid <= SITEID || self::mode() === self::MODE_OFF) {
            return;
        }
        if (!self::get($courseid)) {
            if (self::mode() === self::MODE_AUTO && self::is_shared($courseid)) {
                $d = self::defaults();
                self::setup($courseid, $d['groupmode'], $d['applyactivities'], $d['locked']);
            }
            return;
        }
        $owner = (int)$DB->get_field('local_clientportal_enrol', 'clientid', ['userid' => $userid, 'courseid' => $courseid]);
        if ($owner) {
            self::ensure_group($courseid, $owner);
        }
        $context = context_course::instance($courseid);
        foreach (self::client_groups($courseid) as $clientid => $group) {
            $component = $DB->get_field('groups_members', 'component', ['groupid' => $group->id, 'userid' => $userid]);
            if ($clientid === $owner) {
                if ($component === false && is_enrolled($context, $userid)) {
                    groups_add_member($group, $userid, 'local_clientportal', $clientid);
                }
            } else if ($component === 'local_clientportal') {
                groups_remove_member($group, $userid);
            }
        }
    }

    /**
     * A client was renamed: rename its groups.
     *
     * @param int $clientid
     */
    public static function client_renamed(int $clientid): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');
        $client = manager::get_client($clientid);
        if (!$client) {
            return;
        }
        foreach ($DB->get_records('groups', ['idnumber' => self::GROUP_PREFIX . $clientid]) as $group) {
            $name = format_string($client->name, true, ['context' => context_course::instance((int)$group->courseid)]);
            if ($group->name !== $name) {
                $group->name = $name;
                groups_update_group($group);
            }
        }
    }

    /**
     * A client is being deleted: remove its groups.
     *
     * @param int $clientid
     */
    public static function client_deleted(int $clientid): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');
        foreach ($DB->get_records('groups', ['idnumber' => self::GROUP_PREFIX . $clientid]) as $group) {
            groups_delete_group($group);
        }
    }

    /**
     * What needs attention in a set-up course.
     *
     * @param int $courseid
     * @return array key => message
     */
    public static function issues(int $courseid): array {
        global $DB;
        $out = [];
        $owners = $DB->get_records_menu('local_clientportal_enrol', ['courseid' => $courseid], '', 'userid, clientid');
        $clientgroupids = array_map(fn($g) => (int)$g->id, self::client_groups($courseid));

        // Groups made by the course author that mix learners of different clients.
        $mixed = [];
        $groups = $DB->get_records('groups', ['courseid' => $courseid], 'name', 'id, name');
        foreach ($groups as $group) {
            if (in_array((int)$group->id, $clientgroupids, true)) {
                continue;
            }
            $userids = $DB->get_fieldset_select('groups_members', 'userid', 'groupid = ?', [$group->id]);
            $clients = [];
            foreach ($userids as $userid) {
                if (isset($owners[$userid])) {
                    $clients[(int)$owners[$userid]] = true;
                }
            }
            if (count($clients) > 1) {
                $mixed[] = format_string($group->name);
            }
        }
        if ($mixed) {
            $out['mixed'] = get_string('shared_issue_mixed', 'local_clientportal', implode(', ', $mixed));
        }

        // Client staff in the course who can see every group.
        $context = context_course::instance($courseid);
        $staff = $DB->get_fieldset_sql("SELECT DISTINCT userid FROM {local_clientportal_member}");
        $open = [];
        foreach ($staff as $userid) {
            if (is_enrolled($context, (int)$userid) && has_capability('moodle/site:accessallgroups', $context, (int)$userid)) {
                $open[] = fullname(\core_user::get_user((int)$userid));
            }
        }
        if ($open) {
            $out['allgroups'] = get_string('shared_issue_allgroups', 'local_clientportal', implode(', ', $open));
        }

        // Learners not (yet) in their client's group.
        $missing = 0;
        $groupsbyclient = self::client_groups($courseid);
        foreach ($owners as $userid => $clientid) {
            $group = $groupsbyclient[(int)$clientid] ?? null;
            $ingroup = $group && $DB->record_exists('groups_members', ['groupid' => $group->id, 'userid' => $userid]);
            if (!$ingroup && is_enrolled($context, (int)$userid)) {
                $missing++;
            }
        }
        if ($missing) {
            $out['sync'] = get_string('shared_issue_sync', 'local_clientportal', $missing);
        }
        return $out;
    }

    /**
     * The hourly task: fix set-up courses, and set up new shared courses when the setting is Automatic.
     *
     * @return array ['synced' => int changes, 'setup' => int courses set up]
     */
    public static function run(): array {
        global $DB;
        $result = ['synced' => 0, 'setup' => 0];
        if (self::mode() === self::MODE_OFF) {
            return $result;
        }
        foreach ($DB->get_fieldset_select('local_clientportal_shared', 'courseid', '1 = 1') as $courseid) {
            if (!$DB->record_exists('course', ['id' => $courseid])) {
                $DB->delete_records_select(
                    'local_clientportal_sharedcm',
                    'sharedid IN (SELECT id FROM {local_clientportal_shared} WHERE courseid = ?)',
                    [$courseid]
                );
                $DB->delete_records('local_clientportal_shared', ['courseid' => $courseid]);
                continue;
            }
            $result['synced'] += self::sync_course((int)$courseid);
        }
        if (self::mode() === self::MODE_AUTO) {
            $d = self::defaults();
            foreach (self::not_set_up() as $courseid) {
                self::setup($courseid, $d['groupmode'], $d['applyactivities'], $d['locked']);
                $result['setup']++;
            }
        }
        return $result;
    }

    /**
     * Course name for the activity log.
     *
     * @param int $courseid
     * @return string
     */
    protected static function course_name(int $courseid): string {
        global $DB;
        $name = $DB->get_field('course', 'fullname', ['id' => $courseid]);
        return $name === false ? (string)$courseid : format_string($name, true, ['context' => \context_system::instance()]);
    }
}
