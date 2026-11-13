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

use local_clientportal\integration\enrolment;
use local_clientportal\integration\matrix;
use local_clientportal\integration\pathways;

#[\PHPUnit\Framework\Attributes\CoversClass(matrix::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(pathways::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(enrolment::class)]
/**
 * Tests for onboarding, compliance and pathways (Training Matrix and Training Pathways inside the portal).
 *
 * Skipped when those plugins are not installed.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_clientportal\integration\matrix
 * @covers     \local_clientportal\integration\pathways
 * @covers     \local_clientportal\integration\enrolment
 */
final class integration_test extends \advanced_testcase {
    /**
     * Two clients, a manager, a representative, learners and the definitions they can use.
     *
     * @return array
     */
    private function setup_data(): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        matrix::reset_cache();
        pathways::reset_cache();
        if (!matrix::available() || !pathways::available()) {
            $this->markTestSkipped('Training Matrix and Training Pathways are not installed.');
        }
        $this->setAdminUser();
        set_config('onboardenrol', 1, 'local_clientportal');
        set_config('managersignoff', 1, 'local_clientportal');
        $gen = $this->getDataGenerator();
        $bhp = manager::save_client((object)['name' => 'BHP', 'cohortid' => 0, 'active' => 1]);
        $rio = manager::save_client((object)['name' => 'Rio', 'cohortid' => 0, 'active' => 1]);
        $mgr = $gen->create_user();
        $rep = $gen->create_user();
        $zoe = $gen->create_user();
        $ruby = $gen->create_user();
        manager::add_member($bhp, $mgr->id, manager::ROLE_MANAGER);
        manager::add_member($bhp, $rep->id, manager::ROLE_REP);
        cohort_add_member((int)manager::get_client($bhp)->cohortid, $zoe->id);
        cohort_add_member((int)manager::get_client($rio)->cohortid, $ruby->id);
        $course1 = $gen->create_course(['enablecompletion' => 1]);
        $course2 = $gen->create_course(['enablecompletion' => 1]);
        $now = time();
        $dept = $DB->insert_record(matrix::t('local_tm_departments'), (object)['name' => 'Mine site', 'clientid' => $bhp,
            'parentid' => 0, 'managerid' => 0, 'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now]);
        $pos = $DB->insert_record(matrix::t('local_tm_positions'), (object)['name' => 'Haul truck operator',
            'departmentid' => $dept, 'level' => 1, 'clientid' => $bhp, 'timecreated' => $now, 'timemodified' => $now]);
        $riopos = $DB->insert_record(matrix::t('local_tm_positions'), (object)['name' => 'Rio role', 'departmentid' => 0,
            'level' => 1, 'clientid' => $rio, 'timecreated' => $now, 'timemodified' => $now]);
        $comps = [];
        foreach ([$course1, $course2] as $i => $course) {
            $comps[$i] = $DB->insert_record(matrix::t('local_tm_competencies'), (object)['name' => 'Comp ' . $i, 'code' => 'C' . $i,
                'category' => 'Safety', 'mandatory' => 1, 'clientid' => 0, 'timecreated' => $now, 'timemodified' => $now]);
            $DB->insert_record(matrix::t('local_tm_comp_courses'), (object)['competencyid' => $comps[$i], 'courseid' => $course->id,
                'expirymonths' => 12, 'notifydays' => 30, 'timecreated' => $now]);
            $DB->insert_record(matrix::t('local_tm_pos_comps'), (object)['positionid' => $pos, 'competencyid' => $comps[$i],
                'mandatory' => 1, 'timecreated' => $now]);
        }
        $pathway = $DB->insert_record('local_tp_pathways', (object)['name' => 'BHP induction', 'description' => '',
            'sequential' => 1, 'colour' => 'blue', 'status' => 'active', 'sortorder' => 0, 'timecreated' => $now,
            'timemodified' => $now, 'createdby' => 2, 'clientid' => $bhp]);
        $riopathway = $DB->insert_record('local_tp_pathways', (object)['name' => 'Rio induction', 'description' => '',
            'sequential' => 1, 'colour' => 'blue', 'status' => 'active', 'sortorder' => 0, 'timecreated' => $now,
            'timemodified' => $now, 'createdby' => 2, 'clientid' => $rio]);
        $stage = $DB->insert_record('local_tp_stages', (object)['pathwayid' => $pathway, 'name' => 'Week 1',
            'description' => '', 'sortorder' => 1, 'timecreated' => $now]);
        $coursetask = $DB->insert_record('local_tp_tasks', (object)['stageid' => $stage, 'name' => 'Course',
            'description' => '', 'tasktype' => 'course', 'courseid' => $course1->id, 'required' => 1, 'sortorder' => 1,
            'timecreated' => $now]);
        $signoff = $DB->insert_record('local_tp_tasks', (object)['stageid' => $stage, 'name' => 'Site walk',
            'description' => '', 'tasktype' => 'signoff', 'courseid' => null, 'required' => 1, 'sortorder' => 2,
            'timecreated' => $now]);
        return compact(
            'bhp',
            'rio',
            'mgr',
            'rep',
            'zoe',
            'ruby',
            'course1',
            'course2',
            'dept',
            'pos',
            'riopos',
            'comps',
            'pathway',
            'riopathway',
            'coursetask',
            'signoff'
        );
    }

    /**
     * A scope as if the user had opened the portal.
     *
     * @param int $clientid
     * @param string $role
     * @return scope
     */
    private function scope(int $clientid, string $role): scope {
        return new scope(manager::get_client($clientid), $role);
    }

    public function test_save_staff_enrols_and_claims(): void {
        global $DB;
        $d = $this->setup_data();
        $this->setUser($d['mgr']);
        $scope = $this->scope($d['bhp'], manager::ROLE_MANAGER);
        $result = matrix::save_staff($scope, $d['zoe']->id, (object)['positionid' => $d['pos'], 'startdate' => time(),
            'managerid' => $d['mgr']->id, 'employeeid' => 'E1'], true);
        $this->assertEquals(2, $result['enrolled']);
        $this->assertSame([], $result['blocked']);
        $staff = $DB->get_record(matrix::t('local_tm_staff'), ['userid' => $d['zoe']->id, 'status' => 'active']);
        $this->assertEquals($d['bhp'], $staff->clientid);
        $this->assertEquals($d['dept'], $staff->departmentid);
        foreach ([$d['course1'], $d['course2']] as $course) {
            $this->assertTrue(is_enrolled(\context_course::instance($course->id), $d['zoe']->id));
            $this->assertEquals($d['bhp'], $DB->get_field(
                'local_clientportal_enrol',
                'clientid',
                ['userid' => $d['zoe']->id, 'courseid' => $course->id]
            ));
        }
        $this->assertEquals(2, $DB->count_records(matrix::t('local_tm_completions'), ['userid' => $d['zoe']->id]));
        // Saving again changes the record, never duplicates it.
        matrix::save_staff($scope, $d['zoe']->id, (object)['positionid' => $d['pos'], 'employeeid' => 'E2'], true);
        $this->assertEquals(1, $DB->count_records(matrix::t('local_tm_staff'), ['userid' => $d['zoe']->id]));
    }

    public function test_save_staff_refuses_other_clients(): void {
        $d = $this->setup_data();
        $scope = $this->scope($d['bhp'], manager::ROLE_MANAGER);
        try {
            matrix::save_staff($scope, $d['zoe']->id, (object)['positionid' => $d['riopos']], false);
            $this->fail('Another client\'s position was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_badposition', $e->errorcode);
        }
        $this->expectException(\moodle_exception::class);
        matrix::save_staff($scope, $d['ruby']->id, (object)['positionid' => $d['pos']], false);
    }

    public function test_compliance_matches_training_matrix(): void {
        global $DB;
        $d = $this->setup_data();
        $scope = $this->scope($d['bhp'], manager::ROLE_MANAGER);
        matrix::save_staff($scope, $d['zoe']->id, (object)['positionid' => $d['pos']], false);
        $now = time();
        $DB->insert_record(matrix::t('local_tm_completions'), (object)['userid' => $d['zoe']->id, 'competencyid' => $d['comps'][0],
            'courseid' => $d['course1']->id, 'status' => 'completed', 'progress' => 100, 'completiondate' => $now - DAYSECS,
            'expirydate' => $now + 10 * DAYSECS, 'timecreated' => $now, 'timemodified' => $now]);
        $mine = matrix::compliance($scope)[$d['zoe']->id];
        $theirs = local_trainingmatrix_get_staff_compliance($d['zoe']->id);
        $this->assertEquals($theirs['stats'], $mine['stats']);
        $this->assertSame('expiring_soon', $mine['competencies'][$d['comps'][0]]->status);
        $this->assertSame('not_started', $mine['competencies'][$d['comps'][1]]->status);
        // Rio sees none of BHP's staff.
        $this->assertSame([], matrix::compliance($this->scope($d['rio'], manager::ROLE_MANAGER)));
    }

    public function test_pathway_allocate_and_sign_off(): void {
        global $DB;
        $d = $this->setup_data();
        $this->setUser($d['mgr']);
        $scope = $this->scope($d['bhp'], manager::ROLE_MANAGER);
        $this->assertArrayHasKey($d['pathway'], pathways::all($scope));
        $this->assertArrayNotHasKey($d['riopathway'], pathways::all($scope));
        $id = pathways::allocate($scope, $d['pathway'], $d['zoe']->id, time() - DAYSECS);
        $this->assertSame($id, pathways::allocate($scope, $d['pathway'], $d['zoe']->id, null));
        $a = pathways::assignments($scope, [$d['zoe']->id])[$id];
        $this->assertTrue($a->overdue);
        $this->assertSame(0, $a->percent);
        $this->assertArrayHasKey($d['signoff'], $a->signoffs);

        $progress = pathways::sign_off($scope, $id, $d['signoff']);
        $this->assertSame(50, $progress['percent']);
        $this->assertEquals($d['mgr']->id, $DB->get_field(
            'local_tp_progress',
            'signedoffby',
            ['assignmentid' => $id, 'taskid' => $d['signoff']]
        ));

        // Course tasks are not signed off by hand.
        try {
            pathways::sign_off($scope, $id, $d['coursetask']);
            $this->fail('A course task was signed off.');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_badsignoff', $e->errorcode);
        }
    }

    public function test_pathways_refuse_other_clients_and_representatives(): void {
        $d = $this->setup_data();
        $this->setUser($d['mgr']);
        $scope = $this->scope($d['bhp'], manager::ROLE_MANAGER);
        try {
            pathways::allocate($scope, $d['riopathway'], $d['zoe']->id, null);
            $this->fail('Another client\'s pathway was used.');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_badpathway', $e->errorcode);
        }
        $id = pathways::allocate($scope, $d['pathway'], $d['zoe']->id, null);
        $this->setUser($d['rep']);
        try {
            pathways::sign_off($this->scope($d['bhp'], manager::ROLE_REP), $id, $d['signoff']);
            $this->fail('A representative signed off.');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_managersonly', $e->errorcode);
        }
        set_config('managersignoff', 0, 'local_clientportal');
        $this->setUser($d['mgr']);
        $this->expectException(\moodle_exception::class);
        pathways::sign_off($scope, $id, $d['signoff']);
    }

    public function test_enrolment_skips_blocked_courses(): void {
        global $DB;
        $d = $this->setup_data();
        $scope = $this->scope($d['bhp'], manager::ROLE_MANAGER);
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_DISABLED, ['courseid' => $d['course2']->id, 'enrol' => 'manual']);
        $result = enrolment::enrol_courses($scope, $d['zoe']->id, [$d['course1']->id, $d['course2']->id]);
        $this->assertSame([(int)$d['course1']->id], $result['enrolled']);
        $this->assertCount(1, $result['blocked']);
    }
}
