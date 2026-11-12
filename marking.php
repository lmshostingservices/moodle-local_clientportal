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

/**
 * Client Portal - marking: work waiting to be marked in the client's own courses.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\courses;
use local_clientportal\scope;
use local_clientportal\ui;

require(__DIR__ . '/../../config.php');

require_login(null, false);
// Access comes from client membership, checked by scope::require_portal(), so no require_capability() here.

$scope = scope::require_portal(new moodle_url('/local/clientportal/marking.php'));
if (!$scope->is_manager() && !$scope->is_trainer() && !$scope->is_assessor()) {
    redirect($scope->url('/local/clientportal/index.php'));
}
$all = courses::list($scope);
$courseids = $scope->is_trainer() && !$scope->is_assessor()
    ? courses::taught_by($scope, (int)$USER->id) : array_map(fn($c) => (int)$c->id, $all);
$items = $courseids ? local_clientportal\marking::waiting($scope, $courseids) : [];

echo ui::header($scope, 'marking', get_string('nav_marking', 'local_clientportal'));
$total = array_sum(array_map(fn($i) => $i->count, $items));
$oldest = $items ? min(array_map(fn($i) => $i->oldest, $items)) : null;
echo html_writer::start_div('cport-stats');
echo ui::stat(get_string('stat_towork', 'local_clientportal'), $total, '', $total ? 'warn' : 'good');
echo ui::stat(get_string('stat_activities', 'local_clientportal'), count($items));
echo ui::stat(get_string('stat_oldest', 'local_clientportal'), $oldest ? get_string(
    'daysago',
    'local_clientportal',
    (int)floor((time() - $oldest) / DAYSECS)
) : '–', '', $oldest && $oldest < time() - 7 * DAYSECS ? 'warn' : 'neutral');
echo html_writer::end_div();

if (!$items) {
    echo ui::card('', ui::empty(get_string('nothingtomark', 'local_clientportal')));
    echo ui::footer();
    exit;
}
$table = new html_table();
$table->attributes['class'] = 'table cport-table';
$table->head = [get_string('activity', 'local_clientportal'), get_string('course'), get_string('waiting', 'local_clientportal'),
    get_string('oldestsubmission', 'local_clientportal'), ''];
foreach ($items as $i) {
    $table->data[] = [
        html_writer::div(s($i->name), 'cport-name') . html_writer::div(s($i->typename), 'cport-muted'),
        s($i->coursename),
        html_writer::span($i->count, 'cport-pill cport-pill-late'),
        ui::date($i->oldest),
        html_writer::link($i->url, get_string('markthese', 'local_clientportal'), ['class' => 'btn btn-sm btn-primary']),
    ];
}
echo ui::card('', html_writer::div(html_writer::table($table), 'cport-table-wrap'));
echo ui::footer();
