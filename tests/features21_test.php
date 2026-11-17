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

use local_clientportal\integration\matrix;

#[\PHPUnit\Framework\Attributes\CoversClass(structure::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(ai::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(branding::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(catalogue::class)]
/**
 * Tests for the 2.1 features: AI structure, branded sign-in and the course catalogue.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_clientportal\structure
 * @covers     \local_clientportal\ai
 * @covers     \local_clientportal\branding
 * @covers     \local_clientportal\catalogue
 */
final class features21_test extends \advanced_testcase {
    /**
     * A client.
     *
     * @param string $name
     * @return int
     */
    private function client(string $name = 'Coastline'): int {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        return manager::save_client((object)['name' => $name, 'cohortid' => 0, 'active' => 1, 'billing' => 0,
            'ratecredit' => 0, 'ratelearner' => 0, 'rateenrol' => 0]);
    }

    public function test_json_is_read_from_a_fenced_answer(): void {
        $fence = str_repeat(chr(96), 3);
        $this->assertSame(['a' => 1], ai::json("Sure:\n{$fence}json\n{\"a\":1}\n{$fence}"));
        $this->assertSame(['a' => 1], ai::json('{"a":1} thanks'));
        $this->assertNull(ai::json('no json here'));
    }

    public function test_structure_answer_is_cleaned_and_limited(): void {
        $this->resetAfterTest();
        $depts = [];
        for ($i = 0; $i < 12; $i++) {
            $depts[] = ['name' => "Dept $i", 'positions' => [['name' => 'Role', 'competencies' => [
                ['name' => '<b>Forklift</b>', 'category' => 'Hacking', 'mandatory' => true],
                ['name' => 'forklift', 'category' => 'Safety'],
                ['name' => 'Manual handling', 'category' => 'Safety'],
            ]]]];
        }
        $existing = ['departments' => [5 => 'Dept 0'], 'positions' => [], 'competencies' => [9 => 'Manual Handling']];
        $out = structure::normalise(['departments' => $depts], $existing);
        $this->assertCount(structure::MAX_DEPARTMENTS, $out);
        $this->assertSame(5, $out[0]['existingid']);
        $comps = $out[0]['positions'][0]['competencies'];
        $this->assertCount(2, $comps, 'Duplicate names are dropped.');
        $this->assertSame('Forklift', $comps[0]['name']);
        $this->assertSame('Technical', $comps[0]['category'], 'Unknown categories become Technical.');
        $this->assertSame(9, $comps[1]['existingid'], 'Existing competencies are matched without regard to case.');
    }

    public function test_structure_is_created_for_the_client_and_reuses_existing(): void {
        global $DB;
        $clientid = $this->client();
        matrix::reset_cache();
        if (!matrix::available()) {
            $this->markTestSkipped('Training Matrix is not installed.');
        }
        $scope = new scope(manager::get_client($clientid), manager::ROLE_MANAGER);
        $shared = $DB->insert_record(matrix::t('local_tm_competencies'), (object)['name' => 'Manual handling',
            'clientid' => 0, 'mandatory' => 1, 'timecreated' => time(), 'timemodified' => time()]);
        $comp = fn(string $name) => ['name' => $name, 'code' => '', 'category' => 'Safety', 'description' => '',
            'mandatory' => true, 'existingid' => 0];
        $chosen = [['name' => 'Warehouse', 'description' => '', 'existingid' => 0, 'positions' => [
            ['name' => 'Forklift operator', 'description' => '', 'existingid' => 0,
                'competencies' => [$comp('Manual handling'), $comp('Forklift licence')]],
            ['name' => 'Supervisor', 'description' => '', 'existingid' => 0,
                'competencies' => [$comp('Forklift licence')]],
        ]]];
        $counts = structure::create($scope, $chosen);
        $this->assertSame(['departments' => 1, 'positions' => 2, 'competencies' => 1, 'links' => 3], $counts);
        $positions = $DB->get_records(matrix::t('local_tm_positions'), ['clientid' => $clientid]);
        $this->assertCount(2, $positions);
        $this->assertTrue($DB->record_exists(matrix::t('local_tm_pos_comps'), ['competencyid' => $shared]));
        // Running it again creates nothing new.
        $again = structure::create($scope, $chosen);
        $this->assertSame(['departments' => 0, 'positions' => 0, 'competencies' => 0, 'links' => 0], $again);
    }

    public function test_sign_in_links_cannot_be_guessed(): void {
        $a = $this->client('Alpha');
        $b = manager::save_client((object)['name' => 'Bravo', 'cohortid' => 0, 'active' => 1, 'billing' => 0,
            'ratecredit' => 0, 'ratelearner' => 0, 'rateenrol' => 0]);
        $link = branding::login_url($a)->get_param('c');
        $this->assertSame($a, (int)branding::client_from_param($link)->id);
        $this->assertNull(branding::client_from_param($b . '-' . branding::login_key($a)));
        $this->assertNull(branding::client_from_param($a . '-000000000000'));
        $this->assertNull(branding::client_from_param('nonsense'));
        $client = manager::get_client($a);
        $client->active = 0;
        $GLOBALS['DB']->update_record('local_clientportal_client', $client);
        $this->assertNull(branding::client_from_param($link), 'Inactive clients have no sign-in page.');
    }

    public function test_catalogue_never_shows_another_clients_courses(): void {
        global $DB;
        $alpha = $this->client('Alpha');
        $bravo = manager::save_client((object)['name' => 'Bravo', 'cohortid' => 0, 'active' => 1, 'billing' => 0,
            'ratecredit' => 0, 'ratelearner' => 0, 'rateenrol' => 0]);
        $gen = $this->getDataGenerator();
        $alphacat = courses::ensure_category(manager::get_client($alpha));
        $bravocat = courses::ensure_category(manager::get_client($bravo));
        $mine = $gen->create_course(['category' => $alphacat->id, 'fullname' => 'Alpha induction']);
        $theirs = $gen->create_course(['category' => $bravocat->id, 'fullname' => 'Bravo induction']);
        $open = $gen->create_course(['fullname' => 'Open first aid']);
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_ENABLED, ['courseid' => $open->id, 'enrol' => 'self']);
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_ENABLED, ['courseid' => $theirs->id, 'enrol' => 'self']);
        $DB->set_field('course', 'visible', 1, ['id' => $mine->id]);
        courses::reset_cache();
        $learner = $gen->create_user();
        cohort_add_member((int)manager::get_client($alpha)->cohortid, $learner->id);
        $names = array_map(fn($c) => $c->fullname, catalogue::courses((int)$learner->id, '', 'name'));
        $this->assertSame(['Alpha induction', 'Open first aid'], $names);
        $this->assertSame(['Open first aid'], array_map(
            fn($c) => $c->fullname,
            catalogue::courses((int)$learner->id, 'first aid')
        ));
    }
}
