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
 * Client Portal - help guides for client staff, with screenshots and numbered pins.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\help;
use local_clientportal\scope;
use local_clientportal\ui;

require(__DIR__ . '/../../config.php');

require_login(null, false);

$scope = scope::require_portal(new moodle_url('/local/clientportal/help.php'));
$guides = help::guides($scope);
$id = optional_param('g', '', PARAM_ALPHA);
if (!isset($guides[$id])) {
    $id = array_key_first($guides);
}
$ids = array_keys($guides);
$pos = array_search($id, $ids);
$guide = $guides[$id];
$str = fn(string $key, $a = null) => get_string($key, 'local_clientportal', $a);

// Left: chapters and guides.
$nav = '';
foreach (help::CHAPTERS as $chapter) {
    $links = '';
    foreach ($guides as $gid => $g) {
        if ($g['chapter'] !== $chapter) {
            continue;
        }
        $links .= html_writer::link(
            $scope->url('/local/clientportal/help.php', ['g' => $gid]),
            s($str('help_' . $gid)),
            ['class' => 'cport-help-link' . ($gid === $id ? ' active' : ''), 'aria-current' => $gid === $id ? 'page' : null]
        );
    }
    if ($links !== '') {
        $nav .= html_writer::div(html_writer::div(s($str('helpch_' . $chapter)), 'cport-help-ch') . $links, 'cport-help-group');
    }
}

// Right: the guide.
$img = $OUTPUT->image_url('help/' . $guide['shot'], 'local_clientportal');
$pins = '';
$legend = '';
foreach ($guide['pins'] as $i => [$left, $top, $width, $height]) {
    $n = $i + 1;
    $pins .= html_writer::span('', 'cport-help-ring', ['style' => "left:{$left}%;top:{$top}%;width:{$width}%;height:{$height}%"]);
    $pinleft = min(97, $left + $width);
    $pintop = max(1, $top);
    $pins .= html_writer::span($n, 'cport-help-pin', ['style' => "left:{$pinleft}%;top:{$pintop}%", 'aria-hidden' => 'true']);
    $legend .= html_writer::tag('li', html_writer::span($n, 'cport-help-num') . s($str("help_{$id}_pin{$n}")));
}
$title = $str('help_' . $id);
$shot = html_writer::div(
    html_writer::empty_tag('img', ['src' => $img, 'alt' => $title, 'class' => 'cport-help-img']) . $pins,
    'cport-help-shot'
);
$steps = '';
foreach (preg_split('/(?<=[.!?])\s+(?=[A-Z"])/', $str("help_{$id}_desc")) as $sentence) {
    $steps .= html_writer::tag('li', s($sentence));
}
$pager = '';
if ($pos > 0) {
    $prev = $ids[$pos - 1];
    $pager .= html_writer::link(
        $scope->url('/local/clientportal/help.php', ['g' => $prev]),
        s($str('helpprev', $str('help_' . $prev))),
        ['class' => 'btn cport-help-prev']
    );
}
if ($pos < count($ids) - 1) {
    $next = $ids[$pos + 1];
    $pager .= html_writer::link(
        $scope->url('/local/clientportal/help.php', ['g' => $next]),
        s($str('helpnext', $str('help_' . $next))),
        ['class' => 'btn cport-help-next']
    );
}
$body = html_writer::div(
    html_writer::tag('ol', $steps, ['class' => 'cport-help-steps']) .
    html_writer::link($img, $shot, ['class' => 'cport-help-full', 'target' => '_blank', 'title' => $str('helpenlarge')]) .
    html_writer::tag('ol', $legend, ['class' => 'cport-help-legend']) .
    html_writer::div($pager, 'cport-help-pager'),
    'cport-help-body'
);

echo ui::header($scope, 'help', $str('nav_help'));
echo html_writer::div(s($str('helpintro')), 'cport-muted cport-help-intro');
echo html_writer::div(
    html_writer::tag('nav', $nav, ['class' => 'cport-help-nav', 'aria-label' => $str('nav_help')]) .
    html_writer::div(ui::card($title, $body), 'cport-help-main'),
    'cport-help'
);
echo ui::footer();
