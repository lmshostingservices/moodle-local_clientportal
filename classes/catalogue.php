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

use core_course_list_element;
use moodle_url;
use stdClass;

/**
 * The learner's course catalogue: courses of every client they belong to, plus the site's courses that are open
 * to join, all in one place.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class catalogue {
    /** Sort orders. */
    public const SORTS = ['newest', 'name', 'price'];

    /**
     * Courses for a learner.
     *
     * @param int $userid
     * @param string $search
     * @param string $sort newest|name|price
     * @return stdClass[] id, fullname, summary, image, url, timecreated, enrolled, canjoin, price (float|null),
     *                    pricelabel, clientname (one of the learner's clients, or '')
     */
    public static function courses(int $userid, string $search = '', string $sort = 'newest'): array {
        global $DB;
        $clients = [];
        foreach (people::client_ids($userid) as $clientid) {
            if ($client = manager::get_client($clientid)) {
                $clients[$clientid] = $client;
            }
        }
        // Courses of the learner's clients.
        $owned = [];
        foreach ($clients as $client) {
            foreach (courses::category_ids($client) as $catid) {
                $owned[$catid] = format_string($client->name);
            }
        }
        // Courses anyone may join: an active self or fee enrolment.
        $open = $DB->get_fieldset_sql("SELECT DISTINCT e.courseid
                                         FROM {enrol} e
                                        WHERE e.status = 0 AND e.enrol IN ('self', 'fee')
                                              AND (e.enrolstartdate = 0 OR e.enrolstartdate <= :now1)
                                              AND (e.enrolenddate = 0 OR e.enrolenddate > :now2)", ['now1' => time(),
                                                  'now2' => time()]);
        $enrolled = array_map('intval', array_keys(enrol_get_all_users_courses($userid, true, 'id')));
        $ids = $open;
        if ($owned) {
            [$in, $params] = $DB->get_in_or_equal(array_keys($owned), SQL_PARAMS_NAMED, 'cat');
            $ids = array_merge($ids, $DB->get_fieldset_select('course', 'id', "category $in", $params));
        }
        // Courses owned by a client the learner does not belong to are never listed.
        $ids = array_unique(array_map('intval', $ids));
        $out = [];
        foreach ($ids as $courseid) {
            if ($courseid == SITEID) {
                continue;
            }
            $course = get_course($courseid);
            $owner = courses::owner($courseid);
            if ($owner && !isset($clients[$owner])) {
                continue;
            }
            $context = \context_course::instance($courseid);
            if (!$course->visible && !has_capability('moodle/course:viewhiddencourses', $context, $userid)) {
                continue;
            }
            $name = format_string($course->fullname, true, ['context' => $context]);
            $summary = content_to_text(
                format_text($course->summary, $course->summaryformat, ['context' => $context]),
                $course->summaryformat
            );
            if (
                $search !== '' && \core_text::strpos(
                    \core_text::strtolower($name . ' ' . $summary),
                    \core_text::strtolower($search)
                ) === false
            ) {
                continue;
            }
            [$price, $pricelabel] = self::price($courseid);
            $isenrolled = in_array($courseid, $enrolled, true);
            $out[] = (object)[
                'id' => $courseid,
                'fullname' => $name,
                'summary' => shorten_text($summary, 180),
                'image' => self::image($course),
                'url' => (new moodle_url($isenrolled ? '/course/view.php' : '/enrol/index.php', ['id' => $courseid]))->out(false),
                'timecreated' => (int)$course->timecreated,
                'enrolled' => $isenrolled,
                'canjoin' => !$isenrolled && in_array($courseid, array_map('intval', $open), true),
                'price' => $price,
                'pricelabel' => $pricelabel,
                'clientname' => $owner ? $clients[$owner]->name : '',
            ];
        }
        usort($out, function (stdClass $a, stdClass $b) use ($sort): int {
            if ($sort === 'name') {
                return strcasecmp($a->fullname, $b->fullname);
            }
            if ($sort === 'price') {
                return [$a->price ?? 0, strtolower($a->fullname)] <=> [$b->price ?? 0, strtolower($b->fullname)];
            }
            return [$b->timecreated, $a->id] <=> [$a->timecreated, $b->id];
        });
        return $out;
    }

    /**
     * Lowest fee to join a course.
     *
     * @param int $courseid
     * @return array [float|null price, string label]
     */
    private static function price(int $courseid): array {
        global $DB;
        $fees = $DB->get_records_select(
            'enrol',
            "courseid = ? AND enrol = 'fee' AND status = 0",
            [$courseid],
            'cost',
            'id, cost, currency'
        );
        if (!$fees) {
            return [null, ''];
        }
        $fee = reset($fees);
        $cost = (float)$fee->cost;
        $label = class_exists('\\core_payment\\helper')
            ? \core_payment\helper::get_cost_as_string($cost, (string)$fee->currency) : format_float($cost, 2);
        return [$cost, $label];
    }

    /**
     * The course image, if it has one.
     *
     * @param stdClass $course
     * @return string URL or ''
     */
    private static function image(stdClass $course): string {
        $element = new core_course_list_element($course);
        foreach ($element->get_course_overviewfiles() as $file) {
            if ($file->is_valid_image()) {
                return \moodle_url::make_pluginfile_url(
                    $file->get_contextid(),
                    $file->get_component(),
                    $file->get_filearea(),
                    null,
                    $file->get_filepath(),
                    $file->get_filename()
                )->out(false);
            }
        }
        return '';
    }

    /**
     * Hook: a "Course catalogue" link in the main navigation for people who belong to a client.
     *
     * @param \core\hook\navigation\primary_extend $hook
     */
    public static function primary_navigation(\core\hook\navigation\primary_extend $hook): void {
        global $USER;
        if (during_initial_install() || !isloggedin() || isguestuser() || !people::client_ids((int)$USER->id)) {
            return;
        }
        $hook->get_primaryview()->add(
            get_string('catalogue', 'local_clientportal'),
            new moodle_url('/local/clientportal/catalogue.php'),
            \navigation_node::TYPE_CUSTOM,
            null,
            'local_clientportal_catalogue'
        );
    }
}
