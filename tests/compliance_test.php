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

use local_clientportal\compliance\engine;
use local_clientportal\compliance\resetter;
use local_clientportal\compliance\rules;
use local_clientportal\compliance\view;

#[\PHPUnit\Framework\Attributes\CoversClass(engine::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(rules::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(resetter::class)]
/**
 * Tests for required training: rules, reminders, renewals and the per-learner course reset.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_clientportal\compliance\engine
 * @covers     \local_clientportal\compliance\rules
 * @covers     \local_clientportal\compliance\resetter
 */
final class compliance_test extends \advanced_testcase {
    /** @var scope */
    private scope $scope;

    /** @var \stdClass */
    private \stdClass $course;

    /**
     * A client with a course its learners can be required to complete.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/cohort/lib.php');
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enablecompletion = 1;
        $id = manager::save_client((object)['name' => 'Harbour Health', 'cohortid' => 0, 'active' => 1,
            'billing' => 0, 'ratecredit' => 0, 'ratelearner' => 0, 'rateenrol' => 0]);
        $this->scope = new scope(manager::get_client($id), manager::ROLE_MANAGER, true);
        $this->course = $this->getDataGenerator()->create_course(['fullname' => 'Manual handling',
            'enablecompletion' => 1]);
    }

    /**
     * A learner in the client.
     *
     * @return \stdClass
     */
    private function learner(): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        cohort_add_member((int)$this->scope->client->cohortid, $user->id);
        return $user;
    }

    /**
     * A rule for the course.
     *
     * @param array $extra
     * @return \stdClass
     */
    private function rule(array $extra = []): \stdClass {
        $id = rules::save($this->scope, (object)($extra + [
            'courseid' => $this->course->id, 'audience' => 'all', 'duetype' => 'days', 'duedays' => 30,
            'recurunit' => 'months', 'recurvalue' => 12, 'leaddays' => 30, 'reminders' => '14, 3, 0',
            'overdueevery' => 7, 'managerafter' => 7, 'enabled' => 1,
        ]));
        return rules::get($this->scope, $id);
    }

    /**
     * Mark the course complete for a learner.
     *
     * @param int $userid
     * @param int $time
     */
    private function complete(int $userid, int $time): void {
        $cc = new \completion_completion(['userid' => $userid, 'course' => $this->course->id]);
        $cc->mark_complete($time);
    }

    /**
     * The learner's latest cycle.
     *
     * @param int $ruleid
     * @param int $userid
     * @return \stdClass
     */
    private function latest(int $ruleid, int $userid): \stdClass {
        global $DB;
        $rows = $DB->get_records('local_clientportal_cdue', ['ruleid' => $ruleid, 'userid' => $userid], 'cycle DESC', '*', 0, 1);
        return reset($rows);
    }

    public function test_assigns_enrols_and_messages(): void {
        global $DB;
        $user = $this->learner();
        $rule = $this->rule();
        $sink = $this->redirectMessages();
        $now = time();
        (new engine($now))->run($this->scope->id());
        $row = $this->latest($rule->id, $user->id);
        $this->assertSame('open', $row->status);
        $this->assertEquals(rules::end_of_day($now + 30 * DAYSECS), $row->timedue);
        $this->assertTrue(is_enrolled(\context_course::instance($this->course->id), $user->id));
        $this->assertTrue($DB->record_exists('local_clientportal_enrol', ['userid' => $user->id,
            'courseid' => $this->course->id, 'clientid' => $this->scope->id()]));
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('please complete Manual handling', $messages[0]->subject);
        // Running again does not assign or message twice.
        $sink->clear();
        (new engine($now + 60))->run($this->scope->id());
        $this->assertCount(0, $sink->get_messages());
        $this->assertSame(1, $DB->count_records('local_clientportal_cdue', ['ruleid' => $rule->id]));
        $this->assertSame('ontrack', view::rows($this->scope)[$row->id]->state);
    }

    public function test_reminders_overdue_and_manager_summary(): void {
        $user = $this->learner();
        $boss = $this->getDataGenerator()->create_user();
        manager::add_member($this->scope->id(), (int)$boss->id, manager::ROLE_MANAGER);
        $rule = $this->rule(['duedays' => 10]);
        $sink = $this->redirectMessages();
        $start = time();
        (new engine($start))->run($this->scope->id());
        $due = (int)$this->latest($rule->id, $user->id)->timedue;
        $sink->clear();

        // 3 days before: one reminder (the 14-day point was already passed when assigned).
        $t = $due - 3 * DAYSECS;
        (new engine($t))->run($this->scope->id());
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('is due in 3 days', $messages[0]->subject);
        $sink->clear();
        (new engine($t + HOURSECS))->run($this->scope->id());
        $this->assertCount(0, $sink->get_messages());

        // Due day.
        (new engine($due - HOURSECS))->run($this->scope->id());
        $this->assertStringContainsString('is due today', $sink->get_messages()[0]->subject);
        $sink->clear();

        // Overdue: a reminder, then again a week later; the manager hears once after 7 days.
        (new engine($due + HOURSECS))->run($this->scope->id());
        $this->assertCount(1, $sink->get_messages());
        $this->assertStringContainsString('is overdue', $sink->get_messages()[0]->subject);
        $sink->clear();
        (new engine($due + 3 * DAYSECS))->run($this->scope->id());
        $this->assertCount(0, $sink->get_messages());
        (new engine($due + 7 * DAYSECS + HOURSECS))->run($this->scope->id());
        $subjects = array_map(fn($m) => $m->subject, $sink->get_messages());
        $this->assertCount(2, $subjects);
        $this->assertContains('Harbour Health: 1 person is overdue for Manual handling', $subjects);
        $manager = array_values(array_filter($sink->get_messages(), fn($m) => (int)$m->useridto === (int)$boss->id));
        $this->assertCount(1, $manager);
        $sink->clear();
        (new engine($due + 8 * DAYSECS))->run($this->scope->id());
        $this->assertCount(0, $sink->get_messages());
        $this->assertSame('overdue', view::state($this->latest($rule->id, $user->id), $due + 8 * DAYSECS));
    }

    public function test_completion_then_renewal_clears_progress(): void {
        global $DB;
        $user = $this->learner();
        $rule = $this->rule();
        $quiz = $this->quiz_with_attempt((int)$user->id);
        $this->assertSame(1, $DB->count_records('quiz_attempts', ['quiz' => $quiz->id, 'userid' => $user->id]));
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id]);
        $DB->insert_record('assign_submission', (object)['assignment' => $assign->id, 'userid' => $user->id,
            'groupid' => 0, 'attemptnumber' => 0, 'latest' => 1, 'status' => 'submitted', 'timecreated' => time(),
            'timemodified' => time()]);
        $this->redirectMessages();
        $start = time();
        (new engine($start))->run($this->scope->id());
        $this->complete((int)$user->id, $start + DAYSECS);

        (new engine($start + 2 * DAYSECS))->run($this->scope->id());
        $first = $this->latest($rule->id, $user->id);
        $this->assertSame('completed', $first->status);
        $this->assertEquals($start + DAYSECS, $first->timecompleted);
        $this->assertGreaterThan($start + 360 * DAYSECS, (int)$first->nextdue);

        // Not yet: more than 30 days before the next due date.
        (new engine((int)$first->nextdue - 40 * DAYSECS))->run($this->scope->id());
        $this->assertEquals(1, $this->latest($rule->id, $user->id)->cycle);

        // 30 days before: the renewal opens and the learner's progress is cleared.
        $sink = $this->redirectMessages();
        (new engine((int)$first->nextdue - 29 * DAYSECS))->run($this->scope->id());
        $second = $this->latest($rule->id, $user->id);
        $this->assertEquals(2, $second->cycle);
        $this->assertSame('open', $second->status);
        $this->assertEquals($first->nextdue, $second->timedue);
        $this->assertStringContainsString('time to renew', $sink->get_messages()[0]->subject);
        $this->assertEmpty($DB->get_field('course_completions', 'timecompleted', ['course' => $this->course->id,
            'userid' => $user->id]));
        $this->assertSame(0, $DB->count_records('quiz_attempts', ['quiz' => $quiz->id, 'userid' => $user->id]));
        $latest = $DB->get_record('assign_submission', ['assignment' => $assign->id, 'userid' => $user->id, 'latest' => 1]);
        $this->assertSame('new', $latest->status);
        $this->assertEquals(1, $latest->attemptnumber);
        $this->assertSame(2, $DB->count_records('assign_submission', ['assignment' => $assign->id, 'userid' => $user->id]));
        $history = json_decode($DB->get_field('local_clientportal_cdue', 'history', ['id' => $first->id]), true);
        $types = array_column($history['activities'], 'type');
        $this->assertContains('quiz', $types);
        $this->assertContains('assign', $types);

        // Completing again closes the second round.
        $this->complete((int)$user->id, (int)$first->nextdue - 10 * DAYSECS);
        (new engine((int)$first->nextdue - 9 * DAYSECS))->run($this->scope->id());
        $this->assertSame('completed', $this->latest($rule->id, $user->id)->status);
    }

    public function test_completed_before_rule_counts_and_old_completion_is_due_now(): void {
        $done = $this->learner();
        $old = $this->learner();
        $this->getDataGenerator()->enrol_user($done->id, $this->course->id);
        $this->getDataGenerator()->enrol_user($old->id, $this->course->id);
        $now = time();
        $this->complete((int)$done->id, $now - 30 * DAYSECS);
        $this->complete((int)$old->id, $now - 400 * DAYSECS);
        $rule = $this->rule();
        $this->redirectMessages();
        (new engine($now))->run($this->scope->id());
        $this->assertSame('completed', $this->latest($rule->id, $done->id)->status);
        $this->assertSame('completed', $this->latest($rule->id, $old->id)->status);
        // The old one expired a month ago: the next run opens a renewal that is already overdue.
        (new engine($now + HOURSECS))->run($this->scope->id());
        $this->assertEquals(1, $this->latest($rule->id, $done->id)->cycle);
        $renewal = $this->latest($rule->id, $old->id);
        $this->assertEquals(2, $renewal->cycle);
        $this->assertLessThan($now + DAYSECS, (int)$renewal->timedue);
    }

    public function test_group_audience_and_leaving_cancels(): void {
        global $DB;
        $in = $this->learner();
        $out = $this->learner();
        $groupid = manager::save_group($this->scope->id(), (object)['name' => 'Night shift', 'costcentre' => '',
            'description' => '']);
        manager::add_to_group($this->scope->id(), $groupid, [$in->id]);
        $rule = $this->rule(['audience' => 'group', 'audience_group' => $groupid]);
        $this->redirectMessages();
        (new engine())->run($this->scope->id());
        $this->assertSame(1, $DB->count_records('local_clientportal_cdue', ['ruleid' => $rule->id]));
        $this->assertTrue(rules::covers((int)$in->id, (int)$this->course->id));
        $this->assertFalse(rules::covers((int)$out->id, (int)$this->course->id));
        manager::remove_from_group($this->scope->id(), $groupid, [$in->id]);
        (new engine())->run($this->scope->id());
        $this->assertSame('cancelled', $this->latest($rule->id, $in->id)->status);
        $this->assertFalse(rules::covers((int)$in->id, (int)$this->course->id));
    }

    public function test_rule_only_uses_the_clients_courses(): void {
        $other = $this->getDataGenerator()->create_course();
        $rep = new scope($this->scope->client, manager::ROLE_MANAGER, false);
        $this->assertArrayNotHasKey($other->id, rules::course_options($rep));
        $this->expectException(\moodle_exception::class);
        rules::save($rep, (object)['courseid' => $other->id, 'audience' => 'all', 'enabled' => 1]);
    }

    public function test_next_due_dates(): void {
        $rule = (object)['recurunit' => 'days', 'recurvalue' => 90, 'duetype' => 'days', 'duedate' => 0];
        $t = make_timestamp(2026, 3, 10, 12);
        $this->assertSame(rules::end_of_day($t + 90 * DAYSECS), rules::next_due($rule, $t));
        $rule->recurunit = 'months';
        $rule->recurvalue = 12;
        $this->assertSame(rules::end_of_day(make_timestamp(2027, 3, 10, 12)), rules::next_due($rule, $t));
        $rule->recurunit = 'yearly';
        $rule->duetype = 'date';
        $rule->duedate = make_timestamp(2026, 6, 30);
        // Completed early in the year: due on this year's date (30 June 2026 is more than 60 days later).
        $this->assertSame(rules::end_of_day(make_timestamp(2026, 6, 30)), rules::next_due($rule, $t));
        // Completed close to the date: due next year.
        $this->assertSame(
            rules::end_of_day(make_timestamp(2027, 6, 30)),
            rules::next_due($rule, make_timestamp(2026, 6, 20, 12))
        );
        $rule->recurunit = 'none';
        $this->assertSame(0, rules::next_due($rule, $t));
        $this->assertSame('14,3,0', rules::clean_reminders('3, 14, 0, 3'));
    }

    /**
     * A quiz in the course with one finished attempt by the learner.
     *
     * @param int $userid
     * @return \stdClass quiz
     */
    private function quiz_with_attempt(int $userid): \stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $this->getDataGenerator()->enrol_user($userid, $this->course->id);
        $quizgen = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quiz = $quizgen->create_instance(['course' => $this->course->id, 'questionsperpage' => 0, 'grade' => 10,
            'sumgrades' => 1]);
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => \context_module::instance($quiz->cmid)->id]);
        $question = $qgen->create_question('truefalse', null, ['category' => $cat->id]);
        quiz_add_quiz_question($question->id, $quiz);
        $this->setUser($userid);
        $attempt = $quizgen->create_attempt($quiz->id, $userid);
        $quizgen->submit_responses($attempt->id, [1 => 'True'], false, true);
        $this->setAdminUser();
        return $quiz;
    }
}
