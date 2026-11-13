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

use context_coursecat;
use context_course;
use context_system;
use core_course_category;
use moodle_exception;
use stdClass;

/**
 * A client's own courses: its course category, the courses and sub-categories in it, its teachers and assessors.
 *
 * Each client that may build courses gets one course category, hidden from everyone else. Client managers get the
 * "Client course manager" role on that category only; assessors get the non-editing teacher role there; trainers are
 * enrolled as teachers in the courses they teach. Learners see a course only when they are enrolled in it.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class courses {
    /** Feature: build courses and categories. */
    public const FEATURE_COURSES = 'courses';
    /** Feature: add trainers (teachers). */
    public const FEATURE_TEACHERS = 'teachers';
    /** Feature: assessors who mark in every course. */
    public const FEATURE_ASSESSORS = 'assessors';
    /** All features, in display order. */
    public const FEATURES = [self::FEATURE_COURSES, self::FEATURE_TEACHERS, self::FEATURE_ASSESSORS];
    /** Shortname of the role client managers get on their category. */
    public const COURSE_MANAGER_ROLE = 'clientcoursemanager';


    /**
     * Does the client have this feature switched on?
     *
     * @param stdClass $client
     * @param string $feature
     * @return bool
     */
    public static function has(stdClass $client, string $feature): bool {
        return in_array($feature, explode(',', (string)($client->features ?? '')), true);
    }

    /**
     * Make sure the client course manager role exists with the right permissions.
     *
     * @return int role id
     */
    public static function ensure_roles(): int {
        global $DB;
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => self::COURSE_MANAGER_ROLE]);
        if (!$roleid) {
            $roleid = create_role(
                get_string('coursemanagerrole', 'local_clientportal'),
                self::COURSE_MANAGER_ROLE,
                get_string('coursemanagerrole_desc', 'local_clientportal'),
                'editingteacher'
            );
            $system = context_system::instance();
            foreach (get_default_capabilities('editingteacher') as $cap => $permission) {
                assign_capability($cap, $permission, $roleid, $system->id, true);
            }
        }
        set_role_contextlevels($roleid, [CONTEXT_COURSECAT, CONTEXT_COURSE]);
        $system = context_system::instance();
        $extra = [
            'moodle/course:create', 'moodle/course:update', 'moodle/course:view', 'moodle/course:viewhiddencourses',
            'moodle/course:visibility', 'moodle/course:changefullname', 'moodle/course:changeshortname',
            'moodle/course:changesummary', 'moodle/course:changecategory', 'moodle/course:enrolconfig',
            'moodle/course:enrolreview', 'moodle/category:manage', 'moodle/category:viewhiddencategories',
            'moodle/category:viewcourselist', 'moodle/role:assign', 'moodle/backup:backupcourse',
            'moodle/restore:restorecourse', 'enrol/manual:enrol', 'enrol/manual:unenrol', 'enrol/cohort:config',
        ];
        foreach ($extra as $cap) {
            if (get_capability_info($cap)) {
                assign_capability($cap, CAP_ALLOW, $roleid, $system->id, true);
            }
        }
        // Never site-level powers, whatever a later change to the archetype does.
        foreach (['moodle/course:delete', 'moodle/site:config', 'moodle/role:override', 'moodle/role:safeoverride'] as $cap) {
            if (get_capability_info($cap)) {
                assign_capability($cap, CAP_PROHIBIT, $roleid, $system->id, true);
            }
        }
        $assignable = [];
        foreach (['editingteacher', 'teacher', 'student'] as $shortname) {
            if ($id = $DB->get_field('role', 'id', ['shortname' => $shortname])) {
                $assignable[] = (int)$id;
            }
        }
        foreach ($assignable as $target) {
            if (!$DB->record_exists('role_allow_assign', ['roleid' => $roleid, 'allowassign' => $target])) {
                core_role_set_assign_allowed($roleid, $target);
            }
        }
        return $roleid;
    }

    /**
     * Role id by shortname.
     *
     * @param string $shortname
     * @return int
     */
    public static function role(string $shortname): int {
        global $DB;
        if ($shortname === self::COURSE_MANAGER_ROLE) {
            return self::ensure_roles();
        }
        return (int)$DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * The client's category, created (hidden) when missing.
     *
     * @param stdClass $client
     * @return core_course_category
     */
    public static function ensure_category(stdClass $client): core_course_category {
        global $DB;
        $category = $client->categoryid ? core_course_category::get((int)$client->categoryid, IGNORE_MISSING, true) : null;
        if (!$category) {
            $parent = (int)get_config('local_clientportal', 'categoryparent');
            if ($parent && !core_course_category::get($parent, IGNORE_MISSING, true)) {
                $parent = 0;
            }
            $idnumber = 'clientportal_' . $client->id;
            if ($existing = $DB->get_record('course_categories', ['idnumber' => $idnumber])) {
                $category = core_course_category::get((int)$existing->id, MUST_EXIST, true);
            } else {
                $category = core_course_category::create((object)[
                    'name' => $client->name,
                    'idnumber' => $idnumber,
                    'parent' => $parent,
                    'visible' => 0,
                    'description' => get_string('categorydesc', 'local_clientportal', format_string($client->name)),
                ]);
            }
            $DB->set_field('local_clientportal_client', 'categoryid', $category->id, ['id' => $client->id]);
            $client->categoryid = $category->id;
            self::reset_cache();
        }
        return $category;
    }

    /**
     * Give each staff member the roles their client's features allow, and take away the rest.
     *
     * @param int $clientid
     */
    public static function sync_roles(int $clientid): void {
        global $DB;
        $client = manager::get_client($clientid, MUST_EXIST);
        $coursesrole = self::has($client, self::FEATURE_COURSES);
        $assessors = self::has($client, self::FEATURE_ASSESSORS);
        if (($coursesrole || $assessors) && !$client->categoryid) {
            self::ensure_category($client);
        }
        if (!$client->categoryid || !core_course_category::get((int)$client->categoryid, IGNORE_MISSING, true)) {
            return;
        }
        $context = context_coursecat::instance((int)$client->categoryid);
        $managerrole = self::role(self::COURSE_MANAGER_ROLE);
        $assessorrole = self::role('teacher');
        $members = $DB->get_records('local_clientportal_member', ['clientid' => $clientid]);
        $want = [];
        foreach ($members as $m) {
            if ($coursesrole && $m->role === manager::ROLE_MANAGER) {
                $want[$managerrole][(int)$m->userid] = true;
            }
            if ($assessors && !empty($m->assessor)) {
                $want[$assessorrole][(int)$m->userid] = true;
            }
        }
        foreach ([$managerrole, $assessorrole] as $roleid) {
            $have = $DB->get_records_menu('role_assignments', ['roleid' => $roleid, 'contextid' => $context->id,
                'component' => 'local_clientportal'], '', 'userid, id');
            foreach (array_keys($want[$roleid] ?? []) as $userid) {
                if (!isset($have[$userid])) {
                    role_assign($roleid, $userid, $context->id, 'local_clientportal');
                }
            }
            foreach (array_keys($have) as $userid) {
                if (empty($want[$roleid][$userid])) {
                    role_unassign($roleid, $userid, $context->id, 'local_clientportal');
                }
            }
        }
    }

    /**
     * Ids of the client's category and every category under it.
     *
     * @param stdClass $client
     * @return int[]
     */
    public static function category_ids(stdClass $client): array {
        global $DB;
        if (empty($client->categoryid)) {
            return [];
        }
        $root = $DB->get_record('course_categories', ['id' => $client->categoryid], 'id, path');
        if (!$root) {
            return [];
        }
        $ids = $DB->get_fieldset_select(
            'course_categories',
            'id',
            'path = :path OR ' . $DB->sql_like('path', ':prefix'),
            ['path' => $root->path, 'prefix' => $root->path . '/%']
        );
        return array_map('intval', $ids);
    }

    /**
     * The client's categories as a flat, indented list, id => name.
     *
     * @param stdClass $client
     * @return array
     */
    public static function category_options(stdClass $client): array {
        global $DB;
        $ids = self::category_ids($client);
        if (!$ids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($ids);
        $cats = $DB->get_records_select('course_categories', "id $in", $params, 'path', 'id, name, depth, path, parent');
        $rootdepth = (int)$cats[$client->categoryid]->depth;
        // Sort by path of names so children follow their parent.
        $names = [];
        foreach ($cats as $c) {
            $parts = array_filter(explode('/', $c->path));
            $names[$c->id] = implode(' / ', array_map(fn($id) => isset($cats[$id]) ? $cats[$id]->name : '', $parts));
        }
        uksort($cats, fn($a, $b) => strcasecmp($names[$a], $names[$b]));
        $out = [];
        foreach ($cats as $c) {
            $depth = (int)$c->depth - $rootdepth;
            $out[$c->id] = (int)$c->id === (int)$client->categoryid
                ? get_string('topcategory', 'local_clientportal')
                : str_repeat('— ', $depth) . format_string($c->name);
        }
        return $out;
    }

    /**
     * Which client owns a course (through its category)?
     *
     * @param int $courseid
     * @return int client id, or 0
     */
    public static function owner(int $courseid): int {
        global $DB;
        if ($courseid <= SITEID) {
            return 0;
        }
        $cache = \cache::make_from_params(\cache_store::MODE_REQUEST, 'local_clientportal', 'courseowner');
        $owner = $cache->get($courseid);
        if ($owner === false) {
            $path = (string)$DB->get_field_sql(
                "SELECT cc.path FROM {course} c JOIN {course_categories} cc ON cc.id = c.category WHERE c.id = ?",
                [$courseid]
            );
            $ids = array_values(array_filter(array_map('intval', explode('/', $path))));
            $owner = 0;
            if ($ids) {
                [$in, $params] = $DB->get_in_or_equal($ids);
                $owner = (int)$DB->get_field_select('local_clientportal_client', 'id', "categoryid $in", $params, IGNORE_MULTIPLE);
            }
            $cache->set($courseid, $owner);
        }
        return (int)$owner;
    }

    /**
     * Forget cached owners.
     */
    public static function reset_cache(): void {
        \cache::make_from_params(\cache_store::MODE_REQUEST, 'local_clientportal', 'courseowner')->purge();
    }

    /**
     * The client's courses with learner and teacher counts.
     *
     * @param scope $scope
     * @param int[]|null $onlyids limit to these courses (trainers)
     * @return stdClass[]
     */
    public static function list(scope $scope, ?array $onlyids = null): array {
        global $DB;
        $catids = self::category_ids($scope->client);
        if (!$catids || ($onlyids !== null && !$onlyids)) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED, 'cat');
        $limit = '';
        if ($onlyids !== null) {
            [$cin, $cparams] = $DB->get_in_or_equal($onlyids, SQL_PARAMS_NAMED, 'crs');
            $limit = "AND c.id $cin";
            $params += $cparams;
        }
        $student = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
        $teachers = array_map('intval', $DB->get_fieldset_select('role', 'id', "shortname IN ('editingteacher', 'teacher')"));
        $courses = $DB->get_records_sql(
            "SELECT c.id, c.fullname, c.shortname, c.category, c.visible, c.startdate, c.format, c.timemodified
               FROM {course} c
              WHERE c.category $in $limit
           ORDER BY c.fullname",
            $params
        );
        foreach ($courses as $c) {
            $context = context_course::instance($c->id);
            $c->learners = count_role_users($student, $context);
            $c->teachers = $teachers ? count_role_users($teachers, $context) : 0;
            $c->autoenrol = $DB->record_exists('enrol', ['courseid' => $c->id, 'enrol' => 'cohort',
                'customint1' => $scope->client->cohortid, 'status' => ENROL_INSTANCE_ENABLED]);
        }
        return $courses;
    }

    /**
     * Stop unless the course belongs to this client.
     *
     * @param scope $scope
     * @param int $courseid
     * @return stdClass course
     */
    public static function require_course(scope $scope, int $courseid): stdClass {
        global $DB;
        if (self::owner($courseid) !== $scope->id()) {
            throw new moodle_exception('error_notyourcourse', 'local_clientportal');
        }
        return $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    }

    /**
     * Stop unless the category is the client's.
     *
     * @param scope $scope
     * @param int $categoryid
     */
    public static function require_category(scope $scope, int $categoryid): void {
        if (!in_array($categoryid, self::category_ids($scope->client), true)) {
            throw new moodle_exception('error_notyourcategory', 'local_clientportal');
        }
    }

    /**
     * Create a sub-category.
     *
     * @param scope $scope
     * @param int $parentid
     * @param string $name
     * @return int category id
     */
    public static function create_category(scope $scope, int $parentid, string $name): int {
        self::require_feature($scope, self::FEATURE_COURSES, true);
        self::require_category($scope, $parentid);
        $category = core_course_category::create((object)['name' => trim($name), 'parent' => $parentid, 'visible' => 1]);
        manager::log($scope->id(), 'category_created', null, $name);
        return (int)$category->id;
    }

    /**
     * Rename a sub-category (never the client's own top category).
     *
     * @param scope $scope
     * @param int $categoryid
     * @param string $name
     */
    public static function rename_category(scope $scope, int $categoryid, string $name): void {
        self::require_feature($scope, self::FEATURE_COURSES, true);
        self::require_category($scope, $categoryid);
        if ($categoryid === (int)$scope->client->categoryid) {
            throw new moodle_exception('error_notyourcategory', 'local_clientportal');
        }
        core_course_category::get($categoryid, MUST_EXIST, true)->update(['name' => trim($name)]);
        manager::log($scope->id(), 'category_renamed', null, $name);
    }

    /**
     * Delete an empty sub-category.
     *
     * @param scope $scope
     * @param int $categoryid
     */
    public static function delete_category(scope $scope, int $categoryid): void {
        global $DB;
        self::require_feature($scope, self::FEATURE_COURSES, true);
        self::require_category($scope, $categoryid);
        if ($categoryid === (int)$scope->client->categoryid) {
            throw new moodle_exception('error_notyourcategory', 'local_clientportal');
        }
        if (
            $DB->record_exists('course', ['category' => $categoryid]) ||
                $DB->record_exists('course_categories', ['parent' => $categoryid])
        ) {
            throw new moodle_exception('error_categorynotempty', 'local_clientportal');
        }
        $category = core_course_category::get($categoryid, MUST_EXIST, true);
        $name = $category->name;
        $category->delete_full(false);
        manager::log($scope->id(), 'category_deleted', null, $name);
    }

    /**
     * Stop unless the client has the feature (and, optionally, this person is a manager).
     *
     * @param scope $scope
     * @param string $feature
     * @param bool $managers
     */
    public static function require_feature(scope $scope, string $feature, bool $managers = false): void {
        if (!self::has($scope->client, $feature) || ($managers && !$scope->is_manager())) {
            throw new moodle_exception('error_featureoff', 'local_clientportal');
        }
    }

    /**
     * Is a short name free?
     *
     * @param string $shortname
     * @param int $exceptid
     * @return bool
     */
    public static function shortname_free(string $shortname, int $exceptid = 0): bool {
        global $DB;
        return !$DB->record_exists_select('course', 'shortname = ? AND id <> ?', [$shortname, $exceptid]);
    }

    /**
     * Make a short name from a full name that no other course uses.
     *
     * @param string $fullname
     * @param stdClass $client
     * @return string
     */
    public static function suggest_shortname(string $fullname, stdClass $client): string {
        $words = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $fullname)));
        $base = \core_text::strtoupper(\core_text::substr(implode('', array_map(
            fn($w) => \core_text::substr($w, 0, 3),
            array_slice(array_filter($words), 0, 4)
        )), 0, 12));
        $prefix = \core_text::strtoupper(\core_text::substr(preg_replace('/[^\p{L}\p{N}]+/u', '', $client->name), 0, 6));
        $candidate = $prefix . '-' . ($base ?: 'COURSE');
        $n = 1;
        $try = $candidate;
        while (!self::shortname_free($try)) {
            $n++;
            $try = $candidate . '-' . $n;
        }
        return $try;
    }

    /**
     * Create a course in one of the client's categories.
     *
     * @param scope $scope
     * @param stdClass $data fullname, shortname, category, summary, startdate, format, visible, autoenrol
     * @return stdClass course
     */
    public static function create(scope $scope, stdClass $data): stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        self::require_feature($scope, self::FEATURE_COURSES, true);
        self::require_category($scope, (int)$data->category);
        $shortname = trim((string)($data->shortname ?? '')) ?: self::suggest_shortname($data->fullname, $scope->client);
        if (!self::shortname_free($shortname)) {
            throw new moodle_exception('error_shortnametaken', 'local_clientportal', '', s($shortname));
        }
        $formats = \core_component::get_plugin_list('format');
        $format = isset($formats[$data->format ?? '']) ? $data->format : get_config('moodlecourse', 'format');
        $course = create_course((object)[
            'fullname' => trim($data->fullname),
            'shortname' => $shortname,
            'category' => (int)$data->category,
            'summary' => (string)($data->summary ?? ''),
            'summaryformat' => FORMAT_HTML,
            'startdate' => (int)($data->startdate ?? 0) ?: time(),
            'format' => $format,
            'visible' => empty($data->visible) ? 0 : 1,
            'enablecompletion' => 1,
        ]);
        self::reset_cache();
        if (!empty($data->autoenrol)) {
            self::set_autoenrol($scope, (int)$course->id, true);
        }
        manager::log($scope->id(), 'course_created', null, $course->fullname);
        return $course;
    }

    /**
     * Update a course's basic settings.
     *
     * @param scope $scope
     * @param int $courseid
     * @param stdClass $data
     */
    public static function update(scope $scope, int $courseid, stdClass $data): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        self::require_feature($scope, self::FEATURE_COURSES, true);
        $course = self::require_course($scope, $courseid);
        self::require_category($scope, (int)$data->category);
        $shortname = trim((string)$data->shortname) ?: $course->shortname;
        if (!self::shortname_free($shortname, $courseid)) {
            throw new moodle_exception('error_shortnametaken', 'local_clientportal', '', s($shortname));
        }
        update_course((object)[
            'id' => $courseid,
            'fullname' => trim($data->fullname),
            'shortname' => $shortname,
            'category' => (int)$data->category,
            'summary' => (string)($data->summary ?? ''),
            'summaryformat' => FORMAT_HTML,
            'startdate' => (int)($data->startdate ?? $course->startdate),
            'visible' => empty($data->visible) ? 0 : 1,
        ]);
        self::reset_cache();
        self::set_autoenrol($scope, $courseid, !empty($data->autoenrol));
        manager::log($scope->id(), 'course_updated', null, trim($data->fullname));
    }

    /**
     * Turn "every learner in the organisation is enrolled" on or off (a cohort sync enrolment).
     *
     * @param scope $scope
     * @param int $courseid
     * @param bool $on
     */
    public static function set_autoenrol(scope $scope, int $courseid, bool $on): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/enrol/cohort/locallib.php');
        $course = self::require_course($scope, $courseid);
        $plugin = enrol_get_plugin('cohort');
        if (!$plugin) {
            return;
        }
        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'cohort',
            'customint1' => $scope->client->cohortid], '*', IGNORE_MULTIPLE);
        if ($on) {
            if (!enrol_is_enabled('cohort')) {
                $enabled = array_keys(enrol_get_plugins(true));
                $enabled[] = 'cohort';
                set_config('enrol_plugins_enabled', implode(',', array_unique($enabled)));
            }
            if (!$instance) {
                $plugin->add_instance($course, ['customint1' => $scope->client->cohortid,
                    'roleid' => self::role('student'), 'customint2' => 0]);
            } else if ((int)$instance->status !== ENROL_INSTANCE_ENABLED) {
                $plugin->update_status($instance, ENROL_INSTANCE_ENABLED);
            }
            $trace = new \null_progress_trace();
            enrol_cohort_sync($trace, $courseid);
            // Staff are in the cohort for branding, but they are never learners.
            foreach ($DB->get_records('local_clientportal_member', ['clientid' => $scope->id()]) as $m) {
                $instances = $DB->get_records('enrol', ['courseid' => $courseid, 'enrol' => 'cohort',
                    'customint1' => $scope->client->cohortid]);
                foreach ($instances as $inst) {
                    if ($DB->record_exists('user_enrolments', ['enrolid' => $inst->id, 'userid' => $m->userid])) {
                        $plugin->unenrol_user($inst, $m->userid);
                    }
                }
            }
        } else if ($instance && (int)$instance->status === ENROL_INSTANCE_ENABLED) {
            $plugin->update_status($instance, ENROL_INSTANCE_DISABLED);
        }
    }

    /**
     * Teachers of a course (editing and non-editing), keyed by user id with ->roleshortname.
     *
     * @param int $courseid
     * @return stdClass[]
     */
    public static function teachers(int $courseid): array {
        global $DB;
        $context = context_course::instance($courseid);
        $fields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        return $DB->get_records_sql(
            "SELECT u.id, u.email, $fields, r.shortname AS roleshortname
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('editingteacher', 'teacher')
               JOIN {user} u ON u.id = ra.userid AND u.deleted = 0
              WHERE ra.contextid = :ctx
           ORDER BY u.lastname, u.firstname",
            ['ctx' => $context->id]
        );
    }

    /**
     * Staff who can be made a teacher: the client's trainers and managers, id => name.
     *
     * @param scope $scope
     * @return array
     */
    public static function teacher_options(scope $scope): array {
        $out = [];
        foreach (manager::get_members($scope->id()) as $m) {
            if (in_array($m->role, [manager::ROLE_TRAINER, manager::ROLE_MANAGER], true)) {
                $out[(int)$m->id] = fullname($m) . ' (' . get_string('role_' . $m->role, 'local_clientportal') . ')';
            }
        }
        return $out;
    }

    /**
     * Add a staff member as a teacher in a course.
     *
     * @param scope $scope
     * @param int $courseid
     * @param int $userid
     * @param bool $editing editing teacher (true) or non-editing teacher (false)
     */
    public static function add_teacher(scope $scope, int $courseid, int $userid, bool $editing): void {
        global $DB;
        self::require_feature($scope, self::FEATURE_TEACHERS, true);
        $course = self::require_course($scope, $courseid);
        if (!array_key_exists($userid, self::teacher_options($scope))) {
            throw new moodle_exception('error_notyourstaff', 'local_clientportal');
        }
        $manual = self::manual_instance($course);
        $roleid = self::role($editing ? 'editingteacher' : 'teacher');
        $plugin = enrol_get_plugin('manual');
        $context = context_course::instance($courseid);
        // One teaching role at a time.
        foreach (['editingteacher', 'teacher'] as $shortname) {
            $rid = self::role($shortname);
            if ($rid !== $roleid) {
                role_unassign($rid, $userid, $context->id);
            }
        }
        if (!$DB->record_exists('user_enrolments', ['enrolid' => $manual->id, 'userid' => $userid])) {
            $plugin->enrol_user($manual, $userid, $roleid, time());
        } else {
            role_assign($roleid, $userid, $context->id);
        }
        // Teachers are not learners of the course.
        role_unassign(self::role('student'), $userid, $context->id);
        $DB->delete_records('local_clientportal_enrol', ['userid' => $userid, 'courseid' => $courseid]);
        manager::log($scope->id(), 'teacher_added', $userid, self::course_name($courseid));
    }

    /**
     * Take a teacher out of a course.
     *
     * @param scope $scope
     * @param int $courseid
     * @param int $userid
     */
    public static function remove_teacher(scope $scope, int $courseid, int $userid): void {
        global $DB;
        self::require_feature($scope, self::FEATURE_TEACHERS, true);
        self::require_course($scope, $courseid);
        if (!array_key_exists($userid, self::teacher_options($scope))) {
            throw new moodle_exception('error_notyourstaff', 'local_clientportal');
        }
        $plugin = enrol_get_plugin('manual');
        foreach ($DB->get_records('enrol', ['courseid' => $courseid, 'enrol' => 'manual']) as $instance) {
            $plugin->unenrol_user($instance, $userid);
        }
        manager::log($scope->id(), 'teacher_removed', $userid, self::course_name($courseid));
    }

    /**
     * Enrol learners in a course.
     *
     * @param scope $scope
     * @param int $courseid
     * @param int[] $userids
     * @return int number newly enrolled
     */
    public static function enrol_learners(scope $scope, int $courseid, array $userids): int {
        global $DB;
        self::require_feature($scope, self::FEATURE_COURSES, true);
        $course = self::require_course($scope, $courseid);
        $manual = self::manual_instance($course);
        $plugin = enrol_get_plugin('manual');
        $context = context_course::instance($courseid);
        $count = 0;
        foreach (array_unique(array_map('intval', $userids)) as $userid) {
            $scope->require_learner($userid);
            if (is_enrolled($context, $userid)) {
                continue;
            }
            $plugin->enrol_user($manual, $userid, self::role('student'), time());
            $count++;
        }
        manager::log($scope->id(), 'learners_enrolled', null, get_string(
            'log_enrolleddetails',
            'local_clientportal',
            ['course' => self::course_name($courseid), 'count' => $count]
        ));
        return $count;
    }

    /**
     * Take a learner out of a course (every enrolment method this client controls).
     *
     * @param scope $scope
     * @param int $courseid
     * @param int $userid
     */
    public static function unenrol_learner(scope $scope, int $courseid, int $userid): void {
        global $DB;
        self::require_feature($scope, self::FEATURE_COURSES, true);
        self::require_course($scope, $courseid);
        $scope->require_learner($userid);
        $plugin = enrol_get_plugin('manual');
        foreach ($DB->get_records('enrol', ['courseid' => $courseid, 'enrol' => 'manual']) as $instance) {
            $plugin->unenrol_user($instance, $userid);
        }
        manager::log($scope->id(), 'learner_unenrolled', $userid, self::course_name($courseid));
    }

    /**
     * The course's manual enrolment, added and switched on when missing.
     *
     * @param stdClass $course
     * @return stdClass
     */
    protected static function manual_instance(stdClass $course): stdClass {
        global $DB;
        $plugin = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
        if (!$instance) {
            $id = $plugin->add_instance($course, ['roleid' => self::role('student')]);
            $instance = $DB->get_record('enrol', ['id' => $id], '*', MUST_EXIST);
        } else if ((int)$instance->status !== ENROL_INSTANCE_ENABLED) {
            $plugin->update_status($instance, ENROL_INSTANCE_ENABLED);
            $instance->status = ENROL_INSTANCE_ENABLED;
        }
        return $instance;
    }

    /**
     * A course's name for the activity log.
     *
     * @param int $courseid
     * @return string
     */
    protected static function course_name(int $courseid): string {
        global $DB;
        $name = $DB->get_field('course', 'fullname', ['id' => $courseid]);
        return $name === false ? (string)$courseid : format_string($name, true, ['context' => \context_system::instance()]);
    }

    /**
     * Courses a trainer teaches in this client.
     *
     * @param scope $scope
     * @param int $userid
     * @return int[]
     */
    public static function taught_by(scope $scope, int $userid): array {
        $ids = [];
        foreach (enrol_get_all_users_courses($userid, false, 'id') as $c) {
            if (self::owner((int)$c->id) === $scope->id()) {
                $ids[] = (int)$c->id;
            }
        }
        return $ids;
    }
}
