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

#[\PHPUnit\Framework\Attributes\CoversClass(courses::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(separation::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(marking::class)]
/**
 * Tests for client courses: categories, roles, courses, teachers, enrolment and keeping clients apart.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_clientportal\courses
 * @covers     \local_clientportal\separation
 * @covers     \local_clientportal\marking
 */
final class clientcourses_test extends \advanced_testcase {
    /**
     * Two clients with every feature, a manager, a trainer and learners.
     *
     * @return array
     */
    private function setup_data(): array {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $all = implode(',', courses::FEATURES);
        $bhp = manager::save_client((object)['name' => 'BHP', 'cohortid' => 0, 'active' => 1, 'features' => $all]);
        $rio = manager::save_client((object)['name' => 'Rio', 'cohortid' => 0, 'active' => 1, 'features' => $all]);
        $mgr = $gen->create_user();
        $trainer = $gen->create_user();
        $riomgr = $gen->create_user();
        $zoe = $gen->create_user();
        $ruby = $gen->create_user();
        manager::add_member($bhp, $mgr->id, manager::ROLE_MANAGER);
        manager::add_member($bhp, $trainer->id, manager::ROLE_TRAINER);
        manager::add_member($rio, $riomgr->id, manager::ROLE_MANAGER);
        cohort_add_member((int)manager::get_client($bhp)->cohortid, $zoe->id);
        cohort_add_member((int)manager::get_client($rio)->cohortid, $ruby->id);
        return compact('bhp', 'rio', 'mgr', 'trainer', 'riomgr', 'zoe', 'ruby');
    }

    /**
     * A scope for a client.
     *
     * @param int $clientid
     * @param string $role
     * @return scope
     */
    private function scope(int $clientid, string $role = manager::ROLE_MANAGER): scope {
        return new scope(manager::get_client($clientid), $role);
    }

    public function test_category_and_roles(): void {
        global $DB;
        $d = $this->setup_data();
        $client = manager::get_client($d['bhp']);
        $this->assertNotEmpty($client->categoryid);
        $category = \core_course_category::get((int)$client->categoryid, MUST_EXIST, true);
        $this->assertEquals(0, $category->visible);
        $context = \context_coursecat::instance((int)$client->categoryid);
        $managerrole = courses::role(courses::COURSE_MANAGER_ROLE);
        $this->assertTrue(user_has_role_assignment($d['mgr']->id, $managerrole, $context->id));
        $this->assertFalse(user_has_role_assignment($d['trainer']->id, $managerrole, $context->id));
        $this->assertTrue(has_capability('moodle/course:create', $context, $d['mgr']));
        $this->assertFalse(has_capability('moodle/course:create', \context_coursecat::instance(
            (int)manager::get_client($d['rio'])->categoryid
        ), $d['mgr']));

        // Assessor: non-editing teacher on the category, and back off again.
        manager::set_assessor($d['bhp'], $d['trainer']->id, true);
        $this->assertTrue(user_has_role_assignment($d['trainer']->id, courses::role('teacher'), $context->id));
        manager::set_assessor($d['bhp'], $d['trainer']->id, false);
        $this->assertFalse(user_has_role_assignment($d['trainer']->id, courses::role('teacher'), $context->id));

        // Switching the feature off takes the role away.
        manager::save_client((object)['id' => $d['bhp'], 'name' => 'BHP', 'active' => 1, 'features' => '']);
        $this->assertFalse(user_has_role_assignment($d['mgr']->id, $managerrole, $context->id));
        $this->assertEquals(0, $DB->count_records('role_assignments', ['contextid' => $context->id,
            'component' => 'local_clientportal']));
    }

    public function test_create_course_teachers_and_enrolment(): void {
        global $DB;
        $d = $this->setup_data();
        $this->setUser($d['mgr']);
        $scope = $this->scope($d['bhp']);
        $client = $scope->client;
        $sub = courses::create_category($scope, (int)$client->categoryid, 'Mine site');
        $course = courses::create($scope, (object)['fullname' => 'Haul road safety', 'shortname' => '', 'category' => $sub,
            'format' => 'topics', 'visible' => 1, 'autoenrol' => 1]);
        $this->assertEquals($d['bhp'], courses::owner((int)$course->id));
        $this->assertStringStartsWith('BHP-', $course->shortname);
        $context = \context_course::instance($course->id);
        // Everyone in the organisation is enrolled; staff are not learners.
        $this->assertTrue(is_enrolled($context, $d['zoe']));
        $this->assertFalse(is_enrolled($context, $d['mgr']));
        $this->assertFalse(is_enrolled($context, $d['ruby']));
        $this->assertEquals($d['bhp'], $DB->get_field(
            'local_clientportal_enrol',
            'clientid',
            ['userid' => $d['zoe']->id, 'courseid' => $course->id]
        ));

        courses::add_teacher($scope, (int)$course->id, $d['trainer']->id, false);
        $this->assertArrayHasKey($d['trainer']->id, courses::teachers((int)$course->id));
        $this->assertSame('teacher', courses::teachers((int)$course->id)[$d['trainer']->id]->roleshortname);
        $this->assertSame([(int)$course->id], courses::taught_by($scope, (int)$d['trainer']->id));
        $this->assertTrue(guard::is_portal_user($d['trainer']->id));

        // Another client's manager gets nowhere.
        $rio = $this->scope($d['rio']);
        try {
            courses::enrol_learners($rio, (int)$course->id, [$d['ruby']->id]);
            $this->fail('Another client enrolled into the course.');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_notyourcourse', $e->errorcode);
        }
        // Nor can a learner of another client be enrolled.
        $this->expectException(\moodle_exception::class);
        courses::enrol_learners($scope, (int)$course->id, [$d['ruby']->id]);
    }

    public function test_categories_stay_inside_the_client(): void {
        $d = $this->setup_data();
        $scope = $this->scope($d['bhp']);
        $riocat = (int)manager::get_client($d['rio'])->categoryid;
        $this->expectException(\moodle_exception::class);
        courses::create_category($scope, $riocat, 'Sneaky');
    }

    public function test_cannot_delete_top_or_full_category(): void {
        $d = $this->setup_data();
        $scope = $this->scope($d['bhp']);
        $sub = courses::create_category($scope, (int)$scope->client->categoryid, 'Office');
        courses::create($scope, (object)['fullname' => 'Filing', 'category' => $sub, 'visible' => 1]);
        try {
            courses::delete_category($scope, $sub);
            $this->fail('A category with a course was deleted.');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_categorynotempty', $e->errorcode);
        }
        $this->expectException(\moodle_exception::class);
        courses::delete_category($scope, (int)$scope->client->categoryid);
    }

    public function test_representative_cannot_build(): void {
        $d = $this->setup_data();
        $scope = $this->scope($d['bhp'], manager::ROLE_REP);
        $this->expectException(\moodle_exception::class);
        courses::create($scope, (object)['fullname' => 'Nope', 'category' => (int)$scope->client->categoryid]);
    }

    public function test_guard_lets_staff_into_their_own_courses_only(): void {
        $d = $this->setup_data();
        $scope = $this->scope($d['bhp']);
        $mine = courses::create($scope, (object)['fullname' => 'Mine', 'category' => (int)$scope->client->categoryid]);
        $other = $this->getDataGenerator()->create_course();
        $this->setUser($d['mgr']);
        $this->assertTrue(guard::in_client_course((int)$mine->id));
        $this->assertFalse(guard::in_client_course((int)$other->id));
        $this->setUser($d['riomgr']);
        $this->assertFalse(guard::in_client_course((int)$mine->id));
    }

    public function test_separation_fix(): void {
        global $CFG;
        $this->resetAfterTest();
        set_config('messaging', 1);
        set_config('enableglobalsearch', 1);
        $before = array_filter(separation::checks(), fn($c) => !$c->ok);
        $this->assertArrayHasKey('messaging', $before);
        separation::fix();
        $this->assertEmpty(array_filter(separation::checks(), fn($c) => !$c->ok));
        $this->assertEquals(0, $CFG->messaging);
        $this->assertFalse(has_capability(
            'moodle/category:viewcourselist',
            \context_system::instance(),
            $this->getDataGenerator()->create_user()
        ));
    }

    public function test_marking_lists_waiting_work_for_the_client(): void {
        global $DB;
        $d = $this->setup_data();
        $scope = $this->scope($d['bhp']);
        $course = courses::create($scope, (object)['fullname' => 'Assessed', 'category' => (int)$scope->client->categoryid,
            'autoenrol' => 1]);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1]);
        $DB->insert_record('assign_submission', (object)['assignment' => $assign->id, 'userid' => $d['zoe']->id,
            'timecreated' => time(), 'timemodified' => time(), 'status' => 'submitted', 'latest' => 1, 'attemptnumber' => 0,
            'groupid' => 0]);
        $items = marking::waiting($scope, [(int)$course->id]);
        $this->assertCount(1, $items);
        $this->assertSame(1, $items[0]->count);
    }
}
