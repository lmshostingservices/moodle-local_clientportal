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

#[\PHPUnit\Framework\Attributes\CoversClass(manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(scope::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(guard::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(help::class)]
/**
 * Tests for client staff, scoping, enrolment ownership and groups.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_clientportal\manager
 * @covers     \local_clientportal\scope
 * @covers     \local_clientportal\observer
 * @covers     \local_clientportal\guard
 * @covers     \local_clientportal\help
 */
final class portal_test extends \advanced_testcase {
    /**
     * Two clients, staff and learners.
     *
     * @return array
     */
    private function setup_clients(): array {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('autoclaim', 1, 'local_clientportal');
        $gen = $this->getDataGenerator();
        $bhp = manager::save_client((object)['name' => 'BHP', 'cohortid' => 0, 'active' => 1]);
        $rio = manager::save_client((object)['name' => 'Rio', 'cohortid' => 0, 'active' => 1]);
        $bhpcohort = (int)manager::get_client($bhp)->cohortid;
        $riocohort = (int)manager::get_client($rio)->cohortid;
        $mgr = $gen->create_user();
        $zoe = $gen->create_user();
        $oli = $gen->create_user();
        $ruby = $gen->create_user();
        manager::add_member($bhp, $mgr->id, manager::ROLE_MANAGER);
        cohort_add_member($bhpcohort, $zoe->id);
        cohort_add_member($bhpcohort, $oli->id);
        cohort_add_member($riocohort, $oli->id);
        cohort_add_member($riocohort, $ruby->id);
        return compact('bhp', 'rio', 'bhpcohort', 'mgr', 'zoe', 'oli', 'ruby');
    }

    public function test_staff_join_cohort_get_role_and_are_not_learners(): void {
        global $DB;
        $d = $this->setup_clients();
        $this->assertTrue($DB->record_exists('cohort_members', ['cohortid' => $d['bhpcohort'], 'userid' => $d['mgr']->id]));
        $this->assertTrue(user_has_role_assignment($d['mgr']->id, manager::role_id(), \context_system::instance()->id));
        $this->assertFalse(scope::is_learner($d['bhp'], $d['mgr']->id));
        $this->assertTrue(scope::is_learner($d['bhp'], $d['zoe']->id));
        $this->assertFalse(scope::is_learner($d['bhp'], $d['ruby']->id));
        $this->assertTrue(guard::is_portal_user($d['mgr']->id));
        $this->assertFalse(guard::is_portal_user($d['zoe']->id));

        manager::remove_member($d['bhp'], $d['mgr']->id);
        guard::reset_cache();
        $this->assertFalse(guard::is_portal_user($d['mgr']->id));
        $this->assertFalse(user_has_role_assignment($d['mgr']->id, manager::role_id(), \context_system::instance()->id));
    }

    public function test_enrolled_users_cannot_be_staff(): void {
        $d = $this->setup_clients();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($d['zoe']->id, $course->id);
        $this->expectException(\moodle_exception::class);
        manager::add_member($d['bhp'], $d['zoe']->id, manager::ROLE_REP);
    }

    public function test_new_enrolments_are_owned_only_when_learner_has_one_client(): void {
        global $DB;
        $d = $this->setup_clients();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($d['zoe']->id, $course->id);
        $this->getDataGenerator()->enrol_user($d['oli']->id, $course->id);
        $this->getDataGenerator()->enrol_user($d['ruby']->id, $course->id);
        $this->assertEquals($d['bhp'], $DB->get_field('local_clientportal_enrol', 'clientid', ['userid' => $d['zoe']->id]));
        $this->assertEquals($d['rio'], $DB->get_field('local_clientportal_enrol', 'clientid', ['userid' => $d['ruby']->id]));
        // Oliver is in both clients, so nobody owns his enrolment until an administrator decides.
        $this->assertFalse($DB->record_exists('local_clientportal_enrol', ['userid' => $d['oli']->id]));
        // One owner per enrolment.
        $this->assertTrue(manager::claim($d['rio'], $d['oli']->id, $course->id, 'admin'));
        $this->assertFalse(manager::claim($d['bhp'], $d['oli']->id, $course->id, 'admin'));
    }

    public function test_claim_existing_skips_owned_and_staff(): void {
        $d = $this->setup_clients();
        set_config('autoclaim', 0, 'local_clientportal');
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($d['zoe']->id, $course->id);
        $this->getDataGenerator()->enrol_user($d['oli']->id, $course->id);
        manager::claim($d['rio'], $d['oli']->id, $course->id, 'admin');
        $this->assertEquals(1, manager::claim_existing($d['bhp']));
    }

    public function test_groups_accept_learners_only(): void {
        global $DB;
        $d = $this->setup_clients();
        $g = manager::save_group($d['bhp'], (object)['name' => 'Sales', 'costcentre' => 'CC1']);
        $added = manager::add_to_group($d['bhp'], $g, [$d['zoe']->id, $d['mgr']->id, $d['ruby']->id]);
        $this->assertEquals(1, $added);
        $this->assertEquals(1, $DB->count_records('local_clientportal_groupmem', ['groupid' => $g]));
        // Another client's group cannot be touched.
        $this->expectException(\dml_missing_record_exception::class);
        manager::add_to_group($d['rio'], $g, [$d['ruby']->id]);
    }

    public function test_leaving_cohort_leaves_groups(): void {
        global $DB;
        $d = $this->setup_clients();
        $g = manager::save_group($d['bhp'], (object)['name' => 'Sales']);
        manager::add_to_group($d['bhp'], $g, [$d['zoe']->id]);
        cohort_remove_member($d['bhpcohort'], $d['zoe']->id);
        $this->assertFalse($DB->record_exists('local_clientportal_groupmem', ['userid' => $d['zoe']->id]));
    }

    public function test_report_is_limited_to_owned_enrolments(): void {
        $d = $this->setup_clients();
        $c1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $c2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->getDataGenerator()->enrol_user($d['zoe']->id, $c1->id);
        $this->getDataGenerator()->enrol_user($d['oli']->id, $c1->id);
        manager::claim($d['rio'], $d['oli']->id, $c1->id, 'admin');
        $this->getDataGenerator()->enrol_user($d['oli']->id, $c2->id);
        manager::claim($d['bhp'], $d['oli']->id, $c2->id, 'admin');
        $scope = new scope(manager::get_client($d['bhp']), manager::ROLE_MANAGER);
        $report = new report($scope);
        $pairs = array_map(fn($r) => $r->userid . '-' . $r->courseid, array_values($report->enrolments()));
        sort($pairs);
        $expected = [$d['zoe']->id . '-' . $c1->id, $d['oli']->id . '-' . $c2->id];
        sort($expected);
        $this->assertEquals($expected, $pairs);
        $this->assertTrue($scope->owns($d['oli']->id, $c2->id));
        $this->assertFalse($scope->owns($d['oli']->id, $c1->id));
    }

    public function test_guard_allow_list(): void {
        $this->resetAfterTest();
        $this->assertTrue(guard::allowed('/local/clientportal/index.php'));
        $this->assertTrue(guard::allowed('/login/logout.php'));
        $this->assertTrue(guard::allowed('/theme/styles.php'));
        $this->assertFalse(guard::allowed('/theme/lmslabs/people.php'));
        $this->assertFalse(guard::allowed('/course/view.php'));
        $this->assertFalse(guard::allowed('/login/token.php'));
        $this->assertFalse(guard::allowed('/local/clientportal/admin/clients.php'));
        $this->assertFalse(guard::allowed('/local/clientportal/admin/members.php'));
        set_config('extraallowed', "/user/edit.php\n", 'local_clientportal');
        $this->assertTrue(guard::allowed('/user/edit.php'));
    }

    public function test_privacy_delete_user(): void {
        global $DB;
        $d = $this->setup_clients();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($d['zoe']->id, $course->id);
        $list = new \core_privacy\local\request\approved_contextlist(
            $d['zoe'],
            'local_clientportal',
            [\context_system::instance()->id]
        );
        privacy\provider::delete_data_for_user($list);
        $this->assertFalse($DB->record_exists('local_clientportal_enrol', ['userid' => $d['zoe']->id]));
    }

    /**
     * Help guides: managers see the team guide, representatives don't, and every guide has its strings and image.
     */
    public function test_help_guides(): void {
        global $CFG;
        $this->resetAfterTest();
        $d = $this->setup_clients();
        $client = manager::get_client($d['bhp']);
        $mgr = help::guides(new scope($client, manager::ROLE_MANAGER));
        $rep = help::guides(new scope($client, manager::ROLE_REP));
        $this->assertArrayHasKey('team', $mgr);
        $this->assertArrayNotHasKey('team', $rep);
        $this->assertCount(count($mgr) - 1, $rep);
        $sm = get_string_manager();
        foreach (help::GUIDES as $id => $guide) {
            $this->assertContains($guide['chapter'], help::CHAPTERS);
            $this->assertTrue($sm->string_exists('help_' . $id, 'local_clientportal'));
            $this->assertTrue($sm->string_exists('help_' . $id . '_desc', 'local_clientportal'));
            foreach (array_keys($guide['pins']) as $i) {
                $this->assertTrue($sm->string_exists('help_' . $id . '_pin' . ($i + 1), 'local_clientportal'));
            }
            $this->assertFileExists($CFG->dirroot . '/local/clientportal/pix/help/' . $guide['shot'] . '.jpg');
        }
    }
}
