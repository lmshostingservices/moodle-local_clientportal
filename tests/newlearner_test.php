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

#[\PHPUnit\Framework\Attributes\CoversClass(newlearner::class)]
/**
 * Tests for adding learners from the portal, with and without emails.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_clientportal\newlearner
 */
final class newlearner_test extends \advanced_testcase {
    /**
     * A client with courses switched on, a manager and one of its own courses with a welcome message.
     *
     * @return array
     */
    private function setup_data(): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $bhp = manager::save_client((object)['name' => 'BHP', 'cohortid' => 0, 'active' => 1,
            'features' => courses::FEATURE_COURSES]);
        $rio = manager::save_client((object)['name' => 'Rio', 'cohortid' => 0, 'active' => 1]);
        $mgr = $gen->create_user();
        manager::add_member($bhp, $mgr->id, manager::ROLE_MANAGER);
        $client = manager::get_client($bhp);
        $course = $gen->create_course(['category' => $client->categoryid, 'fullname' => 'Site induction']);
        // Make sure the manual enrolment would normally send a welcome message.
        $DB->set_field('enrol', 'customint1', ENROL_SEND_EMAIL_FROM_NOREPLY, ['courseid' => $course->id, 'enrol' => 'manual']);
        $group = manager::save_group($bhp, (object)['name' => 'Depot']);
        return compact('bhp', 'rio', 'mgr', 'course', 'group');
    }

    /**
     * Manager scope.
     *
     * @param int $clientid
     * @param string $role
     * @return scope
     */
    private function scope(int $clientid, string $role = manager::ROLE_MANAGER): scope {
        return new scope(manager::get_client($clientid), $role);
    }

    public function test_add_without_email_sends_nothing(): void {
        global $DB;
        $d = $this->setup_data();
        $this->setUser($d['mgr']);
        unset_config('noemailever');
        $mails = $this->redirectEmails();
        $messages = $this->redirectMessages();
        $result = newlearner::add($this->scope($d['bhp']), (object)[
            'firstname' => 'Zoe', 'lastname' => 'Ng', 'email' => 'Zoe.Ng@example.com', 'username' => '',
            'password' => '', 'forcechange' => 1, 'sendemail' => 0, 'groups' => [$d['group']],
            'courses' => [$d['course']->id],
        ]);
        $this->assertFalse($result->existing);
        $this->assertEquals('zoe.ng@example.com', $result->username);
        $this->assertNotEmpty($result->password);
        $this->assertEquals(1, $result->enrolled);
        $this->assertEquals(0, $mails->count());
        $this->assertEquals(0, $messages->count());

        $user = $DB->get_record('user', ['id' => $result->user->id]);
        $this->assertEquals('manual', $user->auth);
        $this->assertTrue(validate_internal_user_password($user, $result->password));
        $this->assertEquals(1, get_user_preferences('auth_forcepasswordchange', 0, $user->id));
        $this->assertTrue(scope::is_learner($d['bhp'], (int)$user->id));
        $this->assertEquals($d['bhp'], people::main_client((int)$user->id));
        $this->assertTrue($DB->record_exists('local_clientportal_groupmem', ['groupid' => $d['group'], 'userid' => $user->id]));
        $this->assertTrue(is_enrolled(\context_course::instance($d['course']->id), $user->id));
        // The client owns the enrolment.
        $this->assertTrue($this->scope($d['bhp'])->owns((int)$user->id, (int)$d['course']->id));

        // Logged without the password.
        $log = $DB->get_record('local_clientportal_log', ['action' => 'learner_added', 'relateduserid' => $user->id]);
        $this->assertNotEmpty($log);
        $this->assertStringNotContainsString($result->password, (string)$log->details);
    }

    public function test_add_with_set_password(): void {
        global $DB;
        $d = $this->setup_data();
        $this->setUser($d['mgr']);
        $result = newlearner::add($this->scope($d['bhp']), (object)[
            'firstname' => 'Ali', 'lastname' => 'Khan', 'email' => 'ali@example.com', 'username' => 'ali.k',
            'password' => 'Depot-2026!', 'forcechange' => 0, 'sendemail' => 0,
        ]);
        $user = $DB->get_record('user', ['id' => $result->user->id]);
        $this->assertEquals('ali.k', $user->username);
        $this->assertTrue(validate_internal_user_password($user, 'Depot-2026!'));
        $this->assertEquals(0, get_user_preferences('auth_forcepasswordchange', 0, $user->id));
    }

    public function test_password_policy(): void {
        $d = $this->setup_data();
        set_config('passwordpolicy', 1);
        $this->setUser($d['mgr']);
        $this->expectException(\moodle_exception::class);
        newlearner::add($this->scope($d['bhp']), (object)[
            'firstname' => 'Ali', 'lastname' => 'Khan', 'email' => 'ali@example.com', 'password' => 'abc', 'sendemail' => 0,
        ]);
    }

    public function test_send_email_option(): void {
        global $DB;
        $d = $this->setup_data();
        $this->setUser($d['mgr']);
        unset_config('noemailever');
        $mails = $this->redirectEmails();
        $messages = $this->redirectMessages();
        $result = newlearner::add($this->scope($d['bhp']), (object)[
            'firstname' => 'Sam', 'lastname' => 'Lee', 'email' => 'sam@example.com', 'sendemail' => 1,
            'courses' => [$d['course']->id],
        ]);
        $this->assertNull($result->password);
        // The new-account email, and the course welcome message.
        $this->assertEquals(1, $mails->count());
        $this->assertEquals(1, $messages->count());
        $this->assertEquals(1, get_user_preferences('auth_forcepasswordchange', 0, $result->user->id));
        $this->assertNotEmpty($DB->get_field('user', 'password', ['id' => $result->user->id]));
    }

    public function test_existing_account_is_reused_unchanged(): void {
        global $DB;
        $d = $this->setup_data();
        $existing = $this->getDataGenerator()->create_user(['email' => 'ruby@example.com', 'username' => 'ruby',
            'password' => 'Original-1!']);
        cohort_add_member((int)manager::get_client($d['rio'])->cohortid, $existing->id);
        $this->setUser($d['mgr']);
        $result = newlearner::add($this->scope($d['bhp']), (object)[
            'firstname' => 'X', 'lastname' => 'Y', 'email' => 'RUBY@example.com', 'username' => 'new',
            'password' => 'Other-2!', 'sendemail' => 0,
        ]);
        $this->assertTrue($result->existing);
        $this->assertNull($result->password);
        $user = $DB->get_record('user', ['id' => $existing->id]);
        $this->assertEquals('ruby', $user->username);
        $this->assertTrue(validate_internal_user_password($user, 'Original-1!'));
        $this->assertTrue(scope::is_learner($d['bhp'], (int)$existing->id));
        // Still a Rio learner too, and Rio is still the main organisation.
        $this->assertTrue(scope::is_learner($d['rio'], (int)$existing->id));
        $this->assertEquals($d['rio'], people::main_client((int)$existing->id));
    }

    public function test_existing_account_refused_when_one_account_off(): void {
        $d = $this->setup_data();
        set_config('oneaccount', 0, 'local_clientportal');
        $existing = $this->getDataGenerator()->create_user(['email' => 'ruby@example.com']);
        cohort_add_member((int)manager::get_client($d['rio'])->cohortid, $existing->id);
        $this->setUser($d['mgr']);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_emailinuse', 'local_clientportal'));
        newlearner::add($this->scope($d['bhp']), (object)['email' => 'ruby@example.com', 'sendemail' => 0]);
    }

    public function test_staff_and_admin_cannot_be_added(): void {
        $d = $this->setup_data();
        $this->setUser($d['mgr']);
        try {
            newlearner::add($this->scope($d['bhp']), (object)['email' => $d['mgr']->email, 'sendemail' => 0]);
            $this->fail('Staff added as a learner');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_isstaff', $e->errorcode);
        }
        $this->expectException(\moodle_exception::class);
        newlearner::add($this->scope($d['bhp']), (object)['email' => get_admin()->email, 'sendemail' => 0]);
    }

    public function test_who_can_add(): void {
        $d = $this->setup_data();
        $client = manager::get_client($d['bhp']);
        $this->assertTrue(newlearner::can_add(new scope($client, manager::ROLE_MANAGER)));
        $this->assertFalse(newlearner::can_add(new scope($client, manager::ROLE_REP)));
        $this->assertFalse(newlearner::can_add(new scope($client, manager::ROLE_TRAINER)));
        $this->assertFalse(newlearner::can_add(new scope($client, manager::ROLE_MANAGER, true)));
        set_config('repscanaddlearners', 1, 'local_clientportal');
        $rep = new scope($client, manager::ROLE_REP);
        $this->assertTrue(newlearner::can_add($rep));
        // Representatives can't enrol in courses.
        $this->assertEquals([], newlearner::course_options($rep));
        $this->assertArrayHasKey((int)$d['course']->id, newlearner::course_options(new scope($client, manager::ROLE_MANAGER)));
    }

    public function test_only_own_courses_are_used(): void {
        $d = $this->setup_data();
        $central = $this->getDataGenerator()->create_course();
        $this->setUser($d['mgr']);
        $result = newlearner::add($this->scope($d['bhp']), (object)[
            'firstname' => 'Jo', 'lastname' => 'Bloggs', 'email' => 'jo@example.com', 'sendemail' => 0,
            'courses' => [$central->id],
        ]);
        $this->assertEquals(0, $result->enrolled);
        $this->assertFalse(is_enrolled(\context_course::instance($central->id), $result->user->id));
    }

    public function test_username_rules(): void {
        $d = $this->setup_data();
        $this->setUser($d['mgr']);
        $this->getDataGenerator()->create_user(['username' => 'taken']);
        foreach (['Bad Name' => 'error_badusername', 'taken' => 'error_usernametaken'] as $username => $code) {
            try {
                newlearner::add($this->scope($d['bhp']), (object)['firstname' => 'A', 'lastname' => 'B',
                    'email' => 'a' . random_string(4) . '@example.com', 'username' => $username, 'sendemail' => 0]);
                $this->fail('Username accepted: ' . $username);
            } catch (\moodle_exception $e) {
                $this->assertEquals($code, $e->errorcode);
            }
        }
    }

    public function test_parse_rows(): void {
        $rows = newlearner::parse_rows("firstname,lastname,email\nJo,Smith,jo@example.com\n\nAmy\tWong\tamy@example.com\tamyw\tPass-1!");
        $this->assertCount(2, $rows);
        $this->assertEquals([2, 'Jo', 'Smith', 'jo@example.com', '', ''], $rows[0]);
        $this->assertEquals([4, 'Amy', 'Wong', 'amy@example.com', 'amyw', 'Pass-1!'], $rows[1]);
    }
}
