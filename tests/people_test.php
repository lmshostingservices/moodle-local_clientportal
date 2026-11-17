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

#[\PHPUnit\Framework\Attributes\CoversClass(people::class)]
/**
 * Tests for one account across several clients.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_clientportal\people
 */
final class people_test extends \advanced_testcase {
    /**
     * Two clients.
     *
     * @return array [client a id, client b id]
     */
    private function two_clients(): array {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $make = fn(string $name) => manager::save_client((object)['name' => $name, 'cohortid' => 0, 'active' => 1,
            'billing' => 0, 'ratecredit' => 0, 'ratelearner' => 0, 'rateenrol' => 0]);
        return [$make('Alpha Care'), $make('Bravo Care')];
    }

    /**
     * Join a client as a learner.
     *
     * @param int $clientid
     * @param int $userid
     */
    private function join(int $clientid, int $userid): void {
        cohort_add_member((int)manager::get_client($clientid)->cohortid, $userid);
    }

    public function test_main_client_follows_membership_and_pays(): void {
        [$a, $b] = $this->two_clients();
        $nurse = $this->getDataGenerator()->create_user();
        $this->join($a, $nurse->id);
        $this->assertSame($a, people::main_client((int)$nurse->id));
        $this->join($b, $nurse->id);
        // The first client stays main, and pays for usage that belongs to no client's course.
        $this->assertSame($a, people::main_client((int)$nurse->id));
        $this->assertSame($a, usage::client_for((int)$nurse->id));
        people::set_main_client((int)$nurse->id, $b);
        $this->assertSame($b, usage::client_for((int)$nurse->id));
        // Leaving the main client moves the main to the one left.
        cohort_remove_member((int)manager::get_client($b)->cohortid, $nurse->id);
        $this->assertSame($a, people::main_client((int)$nurse->id));
        cohort_remove_member((int)manager::get_client($a)->cohortid, $nurse->id);
        $this->assertSame(0, people::main_client((int)$nurse->id));
    }

    public function test_main_client_must_be_one_of_theirs(): void {
        [$a, $b] = $this->two_clients();
        $user = $this->getDataGenerator()->create_user();
        $this->join($a, $user->id);
        $this->expectException(\moodle_exception::class);
        people::set_main_client((int)$user->id, $b);
    }

    public function test_adding_staff_by_email_keeps_one_account(): void {
        global $DB;
        [$a, $b] = $this->two_clients();
        $course = $this->getDataGenerator()->create_course();
        $nurse = $this->getDataGenerator()->create_user(['email' => 'Sam.Lee@example.com']);
        $this->getDataGenerator()->enrol_user($nurse->id, $course->id, 'student');
        $this->join($a, $nurse->id);
        $before = $DB->count_records('user');
        $user = manager::create_staff($b, 'Sam', 'Lee', 'sam.lee@example.com', manager::ROLE_TRAINER);
        $this->assertTrue($user->existing);
        $this->assertEquals($nurse->id, $user->id);
        $this->assertSame($before, $DB->count_records('user'));
        $this->assertTrue($DB->record_exists('local_clientportal_member', ['clientid' => $b, 'userid' => $nurse->id]));
        // Learner in Alpha, trainer in Bravo: Bravo stays out of Alpha's learner and stays their staff.
        $this->assertSame([$b => 1], [$b => people::other_client_counts([(int)$nurse->id], $a)[$nurse->id]]);
        $this->assertTrue(guard::is_also_learner((int)$nurse->id));
        // A new email still creates an account.
        $new = manager::create_staff($b, 'Jo', 'Ng', 'jo.ng@example.com', manager::ROLE_TRAINER);
        $this->assertFalse($new->existing);
    }

    public function test_one_account_can_be_switched_off(): void {
        [, $b] = $this->two_clients();
        set_config('oneaccount', 0, 'local_clientportal');
        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user(['email' => 'pat@example.com']);
        $this->getDataGenerator()->enrol_user($learner->id, $course->id, 'student');
        $this->expectException(\moodle_exception::class);
        manager::create_staff($b, 'Pat', 'Kim', 'pat@example.com', manager::ROLE_TRAINER);
    }

    public function test_shared_email_is_refused(): void {
        [$a] = $this->two_clients();
        $this->getDataGenerator()->create_user(['email' => 'shared@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'shared@example.com']);
        $this->expectException(\moodle_exception::class);
        manager::create_staff($a, 'Al', 'Bo', 'shared@example.com', manager::ROLE_MANAGER);
    }

    public function test_other_client_counts_never_count_this_client(): void {
        [$a, $b] = $this->two_clients();
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();
        $this->join($a, $one->id);
        $this->join($a, $two->id);
        $this->join($b, $two->id);
        $counts = people::other_client_counts([(int)$one->id, (int)$two->id], $a);
        $this->assertArrayNotHasKey($one->id, $counts);
        $this->assertSame(1, $counts[$two->id]);
    }
}
