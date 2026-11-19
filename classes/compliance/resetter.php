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

namespace local_clientportal\compliance;

use stdClass;

/**
 * Clears one learner's progress in one course so they can do it again, keeping a summary of what was cleared.
 *
 * Only this learner and this course are touched. Assignments get a new attempt (earlier submissions and feedback
 * stay). Quiz, SCORM, H5P and lesson attempts are removed after their results are summarised. Gradebook history keeps
 * the old grades. Activity and course completion are cleared. Other activities keep their data; only their completion
 * is cleared.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resetter {
    /**
     * Clear a learner's progress in a course.
     *
     * @param int $userid
     * @param int $courseid
     * @return array summary of what was cleared (stored with the finished cycle)
     */
    public static function reset(int $userid, int $courseid): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $summary = ['time' => time(), 'coursegrade' => self::course_grade($userid, $courseid), 'activities' => []];
        $modinfo = get_fast_modinfo($course, $userid);

        $transaction = $DB->start_delegated_transaction();
        foreach ($modinfo->get_cms() as $cm) {
            $done = null;
            switch ($cm->modname) {
                case 'quiz':
                    $done = self::quiz($cm, $userid);
                    break;
                case 'assign':
                    $done = self::assign($cm, $userid);
                    break;
                case 'scorm':
                    $done = self::scorm($cm, $userid);
                    break;
                case 'h5pactivity':
                    $done = self::h5p($cm, $userid);
                    break;
                case 'lesson':
                    $done = self::lesson($cm, $userid);
                    break;
            }
            if ($done) {
                $summary['activities'][] = ['name' => $cm->name, 'type' => $cm->modname] + $done;
            }
        }

        // Grades: removed through the grade API so the gradebook history keeps them.
        $items = \grade_item::fetch_all(['courseid' => $courseid]) ?: [];
        foreach ($items as $item) {
            $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
            if ($grade) {
                $grade->delete('local_clientportal');
            }
        }

        // Completion.
        $cmids = array_keys($modinfo->get_cms());
        if ($cmids) {
            [$in, $params] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');
            $params['userid'] = $userid;
            $DB->delete_records_select('course_modules_completion', "userid = :userid AND coursemoduleid $in", $params);
            $DB->delete_records_select('course_modules_viewed', "userid = :userid AND coursemoduleid $in", $params);
        }
        $DB->delete_records('course_completion_crit_compl', ['course' => $courseid, 'userid' => $userid]);
        $DB->delete_records('course_completions', ['course' => $courseid, 'userid' => $userid]);
        $transaction->allow_commit();

        \cache::make('core', 'completion')->purge();
        \cache::make('core', 'coursecompletion')->purge();
        \cache::make_from_params(\cache_store::MODE_REQUEST, 'core', 'course_progress')->purge();
        return $summary;
    }

    /**
     * The learner's course total, as text.
     *
     * @param int $userid
     * @param int $courseid
     * @return string|null
     */
    public static function course_grade(int $userid, int $courseid): ?string {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $item = \grade_item::fetch_course_item($courseid);
        if (!$item) {
            return null;
        }
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
        if (!$grade || $grade->finalgrade === null) {
            return null;
        }
        return grade_format_gradevalue($grade->finalgrade, $item, true);
    }

    /**
     * Quiz: summarise and remove the learner's attempts.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @return array|null
     */
    protected static function quiz(\cm_info $cm, int $userid): ?array {
        global $DB, $CFG;
        $count = $DB->count_records('quiz_attempts', ['quiz' => $cm->instance, 'userid' => $userid]);
        if (!$count) {
            return null;
        }
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $best = $DB->get_field('quiz_grades', 'grade', ['quiz' => $cm->instance, 'userid' => $userid]);
        $quizobj = \mod_quiz\quiz_settings::create((int)$cm->instance, $userid);
        quiz_delete_user_attempts($quizobj, (object)['id' => $userid]);
        return ['attempts' => $count, 'best' => $best === false ? null : round((float)$best, 2)];
    }

    /**
     * Assignment: start a new, empty attempt. Earlier submissions and feedback stay.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @return array|null
     */
    protected static function assign(\cm_info $cm, int $userid): ?array {
        global $DB;
        $latest = $DB->get_record('assign_submission', ['assignment' => $cm->instance, 'userid' => $userid,
            'groupid' => 0, 'latest' => 1]);
        if (!$latest || $latest->status === 'new') {
            return null;
        }
        $now = time();
        $DB->set_field('assign_submission', 'latest', 0, ['id' => $latest->id]);
        $DB->insert_record('assign_submission', (object)[
            'assignment' => $cm->instance,
            'userid' => $userid,
            'groupid' => 0,
            'attemptnumber' => (int)$latest->attemptnumber + 1,
            'latest' => 1,
            'status' => 'new',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        // Release any lock or extension so the new attempt can be made.
        $DB->delete_records('assign_user_flags', ['assignment' => $cm->instance, 'userid' => $userid]);
        return ['attempt' => (int)$latest->attemptnumber + 1, 'status' => $latest->status];
    }

    /**
     * SCORM: summarise and remove the learner's tracking.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @return array|null
     */
    protected static function scorm(\cm_info $cm, int $userid): ?array {
        global $DB, $CFG;
        $count = $DB->count_records('scorm_attempt', ['scormid' => $cm->instance, 'userid' => $userid]);
        if (!$count) {
            return null;
        }
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');
        scorm_delete_tracks((int)$cm->instance, null, $userid);
        return ['attempts' => $count];
    }

    /**
     * H5P activity: summarise and remove the learner's attempts.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @return array|null
     */
    protected static function h5p(\cm_info $cm, int $userid): ?array {
        global $DB;
        $ids = $DB->get_fieldset_select(
            'h5pactivity_attempts',
            'id',
            'h5pactivityid = ? AND userid = ?',
            [$cm->instance, $userid]
        );
        if (!$ids) {
            return null;
        }
        [$in, $params] = $DB->get_in_or_equal($ids);
        $DB->delete_records_select('h5pactivity_attempts_results', "attemptid $in", $params);
        $DB->delete_records_select('h5pactivity_attempts', "id $in", $params);
        return ['attempts' => count($ids)];
    }

    /**
     * Lesson: summarise and remove the learner's attempts.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @return array|null
     */
    protected static function lesson(\cm_info $cm, int $userid): ?array {
        global $DB;
        $params = ['lessonid' => $cm->instance, 'userid' => $userid];
        $grades = $DB->get_records('lesson_grades', $params, 'grade DESC', 'id, grade');
        $attempts = $DB->count_records('lesson_attempts', $params);
        if (!$grades && !$attempts) {
            return null;
        }
        foreach (['lesson_attempts', 'lesson_grades', 'lesson_timer', 'lesson_branch'] as $table) {
            $DB->delete_records($table, $params);
        }
        $best = $grades ? reset($grades)->grade : null;
        return ['attempts' => count($grades), 'best' => $best === null ? null : round((float)$best, 2)];
    }
}
