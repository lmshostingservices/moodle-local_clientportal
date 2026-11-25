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

#[\PHPUnit\Framework\Attributes\CoversClass(shared::class)]
/**
 * Tests for shared courses: keeping clients apart with Moodle groups.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_clientportal\shared
 */
final class shared_test extends \advanced_testcase {
    /**
     * Two clients with one learner each, enrolled (and owned) in one central course with a forum and a page.
     *
     * @return array
     */
    private function setup_data(): array {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        require_once($CFG->dirroot . '/group/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $bhp = manager::save_client((object)['name' => 'BHP', 'cohortid' => 0, 'active' => 1]);
        $rio = manager::save_client((object)['name' => 'Rio', 'cohortid' => 0, 'active' => 1]);
        $zoe = $gen->create_user();
        $ruby = $gen->create_user();
        $private = $gen->create_user();
        cohort_add_member((int)manager::get_client($bhp)->cohortid, $zoe->id);
        cohort_add_member((int)manager::get_client($rio)->cohortid, $ruby->id);
        $course = $gen->create_course(['groupmode' => NOGROUPS, 'groupmodeforce' => 0]);
        $forum = $gen->create_module('forum', ['course' => $course->id, 'groupmode' => NOGROUPS]);
        $page = $gen->create_module('page', ['course' => $course->id]);
        // Auto-claim gives each enrolment to the learner's only client.
        $gen->enrol_user($zoe->id, $course->id, 'student');
        $gen->enrol_user($ruby->id, $course->id, 'student');
        $gen->enrol_user($private->id, $course->id, 'student');
        return compact('bhp', 'rio', 'zoe', 'ruby', 'private', 'course', 'forum', 'page');
    }

    public function test_detects_shared_course(): void {
        $d = $this->setup_data();
        $this->assertTrue(shared::is_shared((int)$d['course']->id));
        $this->assertEquals([$d['bhp'] => 1, $d['rio'] => 1], shared::clients_in((int)$d['course']->id));
        $this->assertEquals([(int)$d['course']->id], shared::not_set_up());
        $other = $this->getDataGenerator()->create_course();
        $this->assertFalse(shared::is_shared((int)$other->id));
    }

    public function test_setup_creates_groups_and_sets_modes(): void {
        global $DB;
        $d = $this->setup_data();
        $courseid = (int)$d['course']->id;
        shared::setup($courseid, SEPARATEGROUPS, true, false);

        $course = $DB->get_record('course', ['id' => $courseid]);
        $this->assertEquals(SEPARATEGROUPS, (int)$course->groupmode);
        $this->assertEquals(0, (int)$course->groupmodeforce);
        $this->assertEquals(SEPARATEGROUPS, (int)$DB->get_field('course_modules', 'groupmode', ['id' => $d['forum']->cmid]));

        $groups = shared::client_groups($courseid);
        $this->assertCount(2, $groups);
        $this->assertEquals('BHP', $groups[$d['bhp']]->name);
        $this->assertTrue(groups_is_member($groups[$d['bhp']]->id, $d['zoe']->id));
        $this->assertFalse(groups_is_member($groups[$d['bhp']]->id, $d['ruby']->id));
        $this->assertTrue(groups_is_member($groups[$d['rio']]->id, $d['ruby']->id));
        // Not owned by a client: in no client group.
        $this->assertEmpty(groups_get_user_groups($courseid, $d['private']->id)[0]);
        // Memberships are marked as ours, so teachers can't remove them by accident.
        $component = $DB->get_field('groups_members', 'component', ['groupid' => $groups[$d['bhp']]->id,
            'userid' => $d['zoe']->id]);
        $this->assertEquals('local_clientportal', $component);
        $grouping = groups_get_grouping_by_idnumber($courseid, shared::GROUPING);
        $this->assertNotEmpty($grouping);
        $this->assertCount(2, groups_get_all_groups($courseid, 0, $grouping->id));

        // Learners only see their own client in separate groups mode.
        $this->setUser($d['zoe']);
        $context = \context_course::instance($courseid);
        $this->assertFalse(has_capability('moodle/site:accessallgroups', $context));
        $visible = array_keys(groups_get_activity_allowed_groups(get_fast_modinfo($courseid)->get_cm($d['forum']->cmid)));
        $this->assertEquals([(int)$groups[$d['bhp']]->id], array_map('intval', $visible));
    }

    public function test_undo_restores_everything(): void {
        global $DB;
        $d = $this->setup_data();
        $courseid = (int)$d['course']->id;
        $DB->set_field('course', 'groupmode', VISIBLEGROUPS, ['id' => $courseid]);
        shared::set_cm_groupmode($courseid, (int)$d['forum']->cmid, VISIBLEGROUPS);
        shared::setup($courseid, SEPARATEGROUPS, true, true);
        $this->assertEquals(1, (int)$DB->get_field('course', 'groupmodeforce', ['id' => $courseid]));

        shared::undo($courseid, false);
        $course = $DB->get_record('course', ['id' => $courseid]);
        $this->assertEquals(VISIBLEGROUPS, (int)$course->groupmode);
        $this->assertEquals(0, (int)$course->groupmodeforce);
        $this->assertEquals(VISIBLEGROUPS, (int)$DB->get_field('course_modules', 'groupmode', ['id' => $d['forum']->cmid]));
        $this->assertNull(shared::get($courseid));
        $this->assertCount(2, shared::client_groups($courseid));
        $this->assertEquals(0, $DB->count_records('local_clientportal_sharedcm'));

        shared::setup($courseid, SEPARATEGROUPS, false, false);
        shared::undo($courseid, true);
        $this->assertCount(0, shared::client_groups($courseid));
        $this->assertFalse(groups_get_grouping_by_idnumber($courseid, shared::GROUPING));
    }

    public function test_without_apply_activities_leaves_activities(): void {
        global $DB;
        $d = $this->setup_data();
        shared::setup((int)$d['course']->id, VISIBLEGROUPS, false, false);
        $this->assertEquals(NOGROUPS, (int)$DB->get_field('course_modules', 'groupmode', ['id' => $d['forum']->cmid]));
        $this->assertEquals(VISIBLEGROUPS, (int)$DB->get_field('course', 'groupmode', ['id' => $d['course']->id]));
    }

    public function test_new_enrolment_joins_client_group(): void {
        $d = $this->setup_data();
        $courseid = (int)$d['course']->id;
        shared::setup($courseid, SEPARATEGROUPS, true, false);
        $amy = $this->getDataGenerator()->create_user();
        cohort_add_member((int)manager::get_client($d['rio'])->cohortid, $amy->id);
        $this->getDataGenerator()->enrol_user($amy->id, $courseid, 'student');
        $groups = shared::client_groups($courseid);
        $this->assertTrue(groups_is_member($groups[$d['rio']]->id, $amy->id));
    }

    public function test_claim_moves_between_groups_and_release_removes(): void {
        global $DB;
        $d = $this->setup_data();
        $courseid = (int)$d['course']->id;
        shared::setup($courseid, SEPARATEGROUPS, true, false);
        $groups = shared::client_groups($courseid);

        // The private learner is given to BHP by an administrator.
        manager::claim($d['bhp'], (int)$d['private']->id, $courseid, 'admin');
        $this->assertTrue(groups_is_member($groups[$d['bhp']]->id, $d['private']->id));

        // Zoe's enrolment is released: she leaves the BHP group.
        $claim = $DB->get_record('local_clientportal_enrol', ['userid' => $d['zoe']->id, 'courseid' => $courseid]);
        manager::release_claim((int)$claim->id);
        $this->assertFalse(groups_is_member($groups[$d['bhp']]->id, $d['zoe']->id));
    }

    public function test_sync_repairs_missed_memberships_and_keeps_author_members(): void {
        $d = $this->setup_data();
        $courseid = (int)$d['course']->id;
        shared::setup($courseid, SEPARATEGROUPS, true, false);
        $groups = shared::client_groups($courseid);
        groups_remove_member($groups[$d['bhp']]->id, $d['zoe']->id);
        // A teacher put the private learner in the BHP group by hand: not ours, so it stays.
        groups_add_member($groups[$d['bhp']]->id, $d['private']->id);
        $this->assertArrayHasKey('sync', shared::issues($courseid));

        $result = shared::run();
        $this->assertEquals(1, $result['synced']);
        $this->assertTrue(groups_is_member($groups[$d['bhp']]->id, $d['zoe']->id));
        $this->assertTrue(groups_is_member($groups[$d['bhp']]->id, $d['private']->id));
        $this->assertArrayNotHasKey('sync', shared::issues($courseid));
    }

    public function test_issues_mixed_group_and_staff_with_all_groups(): void {
        $d = $this->setup_data();
        $courseid = (int)$d['course']->id;
        shared::setup($courseid, SEPARATEGROUPS, true, false);
        $gen = $this->getDataGenerator();
        $mixed = $gen->create_group(['courseid' => $courseid, 'name' => 'Intake 1']);
        groups_add_member($mixed->id, $d['zoe']->id);
        groups_add_member($mixed->id, $d['ruby']->id);
        $trainer = $gen->create_user(['firstname' => 'Tia', 'lastname' => 'Trainer']);
        manager::add_member($d['bhp'], $trainer->id, manager::ROLE_TRAINER);
        $gen->enrol_user($trainer->id, $courseid, 'editingteacher');

        $issues = shared::issues($courseid);
        $this->assertStringContainsString('Intake 1', $issues['mixed']);
        $this->assertStringContainsString('Tia Trainer', $issues['allgroups']);
        $this->assertEquals('attention', shared::overview()[$courseid]->status);
    }

    public function test_automatic_mode_sets_up_on_second_client(): void {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('sharedcourses', shared::MODE_AUTO, 'local_clientportal');
        $gen = $this->getDataGenerator();
        $bhp = manager::save_client((object)['name' => 'BHP', 'cohortid' => 0, 'active' => 1]);
        $rio = manager::save_client((object)['name' => 'Rio', 'cohortid' => 0, 'active' => 1]);
        $zoe = $gen->create_user();
        $ruby = $gen->create_user();
        cohort_add_member((int)manager::get_client($bhp)->cohortid, $zoe->id);
        cohort_add_member((int)manager::get_client($rio)->cohortid, $ruby->id);
        $course = $gen->create_course();
        $gen->enrol_user($zoe->id, $course->id, 'student');
        $this->assertNull(shared::get((int)$course->id));
        $gen->enrol_user($ruby->id, $course->id, 'student');
        $this->assertNotNull(shared::get((int)$course->id));
        $groups = shared::client_groups((int)$course->id);
        $this->assertTrue(groups_is_member($groups[$bhp]->id, $zoe->id));
        $this->assertTrue(groups_is_member($groups[$rio]->id, $ruby->id));
    }

    public function test_off_mode_does_nothing(): void {
        $d = $this->setup_data();
        set_config('sharedcourses', shared::MODE_OFF, 'local_clientportal');
        $this->assertEquals(['synced' => 0, 'setup' => 0], shared::run());
        $this->assertArrayNotHasKey('sharedcourses', separation::checks());
    }

    public function test_client_rename_and_delete(): void {
        $d = $this->setup_data();
        $courseid = (int)$d['course']->id;
        shared::setup($courseid, SEPARATEGROUPS, true, false);
        $client = manager::get_client($d['bhp']);
        $client->name = 'BHP Iron Ore';
        manager::save_client($client);
        $this->assertEquals('BHP Iron Ore', shared::client_groups($courseid)[$d['bhp']]->name);
        manager::delete_client($d['rio']);
        $this->assertArrayNotHasKey($d['rio'], shared::client_groups($courseid));
    }

    public function test_clients_own_course_is_never_shared(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $bhp = manager::save_client((object)['name' => 'BHP', 'cohortid' => 0, 'active' => 1,
            'features' => courses::FEATURE_COURSES]);
        $client = manager::get_client($bhp);
        $course = $this->getDataGenerator()->create_course(['category' => $client->categoryid]);
        $this->expectException(\moodle_exception::class);
        shared::setup((int)$course->id, SEPARATEGROUPS, true, false);
    }

    public function test_separation_check_and_fix(): void {
        $d = $this->setup_data();
        $checks = separation::checks();
        $this->assertFalse($checks['sharedcourses']->ok);
        separation::fix('sharedcourses');
        $this->assertNotNull(shared::get((int)$d['course']->id));
        $this->assertTrue(separation::checks()['sharedcourses']->ok);
    }

    public function test_reports_still_scoped_by_owned_enrolment(): void {
        $d = $this->setup_data();
        $courseid = (int)$d['course']->id;
        shared::setup($courseid, SEPARATEGROUPS, true, false);
        // A teacher adds Ruby (Rio) to BHP's group by hand: BHP still does not own her enrolment.
        $groups = shared::client_groups($courseid);
        groups_add_member($groups[$d['bhp']]->id, $d['ruby']->id);
        $scope = new scope(manager::get_client($d['bhp']), manager::ROLE_MANAGER);
        $this->assertFalse($scope->owns((int)$d['ruby']->id, $courseid));
        $this->assertTrue($scope->owns((int)$d['zoe']->id, $courseid));
    }
}
