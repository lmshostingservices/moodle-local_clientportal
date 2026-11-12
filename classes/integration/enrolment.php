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

namespace local_clientportal\integration;

use local_clientportal\manager;
use local_clientportal\scope;

/**
 * Enrols a client's learner in courses during onboarding. The client owns each enrolment it makes.
 *
 * Only a manual enrolment is used. A course with no usable manual enrolment, a prerequisite enrolment method, or
 * a suspended enrolment for the learner is reported back and skipped (suspensions are never silently lifted).
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrolment {
    /**
     * May onboarding enrol learners on this site?
     *
     * @return bool
     */
    public static function allowed(): bool {
        return (bool)get_config('local_clientportal', 'onboardenrol');
    }

    /**
     * Enrol the learner in each course.
     *
     * @param scope $scope
     * @param int $userid
     * @param int[] $courseids
     * @return array ['enrolled' => int[] course ids newly enrolled, 'ready' => int[] course ids the learner is
     *               actively enrolled in now, 'blocked' => string[] course names]
     */
    public static function enrol_courses(scope $scope, int $userid, array $courseids): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/enrollib.php');
        $scope->require_learner($userid);
        $result = ['enrolled' => [], 'ready' => [], 'blocked' => []];
        $plugin = enrol_get_plugin('manual');
        foreach (array_unique(array_map('intval', $courseids)) as $courseid) {
            $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname');
            if (!$course || $courseid == SITEID) {
                continue;
            }
            $context = \context_course::instance($courseid);
            if (is_enrolled($context, $userid, '', true)) {
                $result['ready'][] = $courseid;
                continue;
            }
            $manual = null;
            $blocked = is_enrolled($context, $userid, '', false);
            foreach (enrol_get_instances($courseid, true) as $instance) {
                if (in_array($instance->enrol, ['prerequisite', 'prereq2'], true)) {
                    $blocked = true;
                }
                if ($instance->enrol === 'manual') {
                    $manual = $instance;
                }
            }
            if ($blocked || !$manual || !$plugin || !$plugin->allow_enrol($manual) || empty($manual->roleid)) {
                $result['blocked'][] = format_string($course->fullname);
                continue;
            }
            // Claim first, so the auto-claim observer leaves the enrolment to this client.
            manager::claim($scope->id(), $userid, $courseid, 'onboarding');
            $plugin->enrol_user($manual, $userid, $manual->roleid, time());
            manager::log($scope->id(), 'onboarding_enrolled', $userid, (string)$courseid);
            $result['enrolled'][] = $courseid;
            $result['ready'][] = $courseid;
        }
        return $result;
    }
}
