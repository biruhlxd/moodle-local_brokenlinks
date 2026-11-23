<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_brokenlinks\local;

/**
 * Describes where a reported link was found, for the report page and its download.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_helper {
    /**
     * Where a link was found.
     *
     * @param \stdClass $link A local_brokenlinks_link record.
     * @param \stdClass $course
     * @param \course_modinfo $modinfo
     * @return array 'where' => name of the activity, section or course, 'whereurl' => link to it or '',
     *     'cm' => \cm_info|null, 'location' => the field or package file, 'place' => where inside the
     *     package, such as the Rise lesson and block, or '', 'previewurl' => a page opening the package there, or '';
     *     the place and page come from other plugins, see {@see \local_brokenlinks\hook\link_place}
     */
    public static function describe(\stdClass $link, \stdClass $course, \course_modinfo $modinfo): array {
        if ($link->sourcetype === 'block') {
            return self::describe_block((int)$link->location) + ['cm' => null, 'place' => '', 'previewurl' => ''];
        }
        if ($link->sourcetype === 'setting') {
            return self::describe_setting($link->location) + ['cm' => null, 'place' => '', 'previewurl' => ''];
        }
        $where = get_string('source_course', 'local_brokenlinks');
        $whereurl = (new \moodle_url('/course/edit.php', ['id' => $course->id]))->out(false);
        if ($course->id == SITEID) {
            $where = get_string('source_sitehome', 'local_brokenlinks');
            $whereurl = (new \moodle_url('/admin/settings.php', ['section' => 'frontpagesettings']))->out(false);
        }
        $cm = null;
        $cms = $modinfo->get_cms();
        if ($link->cmid && isset($cms[$link->cmid])) {
            $cm = $cms[$link->cmid];
            $where = $cm->get_formatted_name(['escape' => false]);
            $whereurl = $cm->url ? $cm->url->out(false) : '';
        } else if ($link->sectionid) {
            try {
                $section = $modinfo->get_section_info_by_id($link->sectionid);
                $where = $section ? get_section_name($course, $section) : $where;
                $whereurl = $section ? course_get_url($course, $section->section)->out(false) : $whereurl;
            } catch (\Throwable $e) {
                // The section was deleted after the scan.
                $whereurl = '';
            }
        }

        $location = $link->location;
        if ($link->sourcetype === 'scorm') {
            $location = get_string('location_scormfile', 'local_brokenlinks', $link->location);
        } else if ($location === 'intro' || $location === 'summary') {
            $location = get_string('location_description', 'local_brokenlinks');
        } else if (get_string_manager()->string_exists('location_' . $location, 'local_brokenlinks')) {
            $location = get_string('location_' . $location, 'local_brokenlinks');
        }
        $place = new \local_brokenlinks\hook\link_place($link, $cm);
        if (!empty($link->place)) {
            \core\di::get(\core\hook\manager::class)->dispatch($place);
        }
        return ['where' => $where, 'whereurl' => $whereurl, 'cm' => $cm, 'location' => $location,
            'place' => $place->get_description(), 'previewurl' => $place->get_previewurl()];
    }

    /**
     * Where a link found in a block is: the block, and the page it is on.
     *
     * @param int $blockid
     * @return array 'where', 'whereurl' and 'location', as {@see describe()} returns them.
     */
    protected static function describe_block(int $blockid): array {
        global $DB;
        $instance = $DB->get_record('block_instances', ['id' => $blockid]);
        $block = $instance ? block_instance($instance->blockname, $instance) : false;
        if (!$block) {
            // The block was deleted after the scan.
            return ['where' => get_string('source_block', 'local_brokenlinks'), 'whereurl' => '',
                'location' => get_string('location_block', 'local_brokenlinks', '')];
        }
        $title = $block->get_title() ?: get_string('pluginname', 'block_' . $instance->blockname);
        $location = get_string('location_block', 'local_brokenlinks', $title);

        $context = \context::instance_by_id($instance->parentcontextid);
        if ($instance->pagetypepattern === 'epb-page-publish' && is_numeric($instance->subpagepattern)) {
            // A page made with Edwiser Page Builder.
            $name = $DB->get_field('local_edwiserpagebuilder_pages', 'pagename', ['id' => $instance->subpagepattern]);
            return [
                'where' => get_string('source_edwiserpage', 'local_brokenlinks', format_string((string)$name)),
                'whereurl' => (new \moodle_url('/local/edwiserpagebuilder/page.php', ['id' => $instance->subpagepattern]))
                    ->out(false),
                'location' => $location,
            ];
        }
        if ($context->contextlevel == CONTEXT_MODULE) {
            $cm = get_fast_modinfo($context->get_course_context()->instanceid)->get_cm($context->instanceid);
            return ['where' => $cm->get_formatted_name(['escape' => false]),
                'whereurl' => $cm->url ? $cm->url->out(false) : '', 'location' => $location];
        }
        if ($context->contextlevel == CONTEXT_COURSE && $context->instanceid != SITEID) {
            return ['where' => get_string('source_coursepage', 'local_brokenlinks'),
                'whereurl' => (new \moodle_url('/course/view.php', ['id' => $context->instanceid]))->out(false),
                'location' => $location];
        }
        if (str_starts_with($instance->pagetypepattern, 'my-')) {
            return ['where' => get_string('source_dashboard', 'local_brokenlinks'),
                'whereurl' => (new \moodle_url('/my/indexsys.php'))->out(false), 'location' => $location];
        }
        return ['where' => get_string('source_sitehome', 'local_brokenlinks'),
            'whereurl' => (new \moodle_url('/', ['redirect' => 0]))->out(false), 'location' => $location];
    }

    /**
     * Where a link found in a site setting is.
     *
     * @param string $location The setting, as "component/name".
     * @return array 'where', 'whereurl' and 'location', as {@see describe()} returns them.
     */
    protected static function describe_setting(string $location): array {
        [$component, $name] = explode('/', $location, 2);
        $strings = get_string_manager();
        if ($component === 'core') {
            $label = get_string('setting_' . $name, 'local_brokenlinks');
            $where = get_string('source_settings', 'local_brokenlinks');
        } else {
            $label = $strings->string_exists($name, $component) ? get_string($name, $component) : $name;
            $where = get_string('source_theme', 'local_brokenlinks', get_string('pluginname', $component));
        }
        return [
            'where' => $where,
            'whereurl' => (new \moodle_url('/admin/search.php', ['query' => $name]))->out(false),
            'location' => $label,
        ];
    }

    /**
     * Group the report's rows for the same link with the same problem.
     *
     * @param \stdClass[] $links local_brokenlinks_link records, in report order.
     * @return array[] Each group: the records, in report order. Groups are in the order of their first record.
     */
    public static function group_links(array $links): array {
        $groups = [];
        foreach ($links as $link) {
            // Problems without a link, such as an empty package, are each their own row.
            $key = (string)$link->url === '' ? 'id' . $link->id
                : implode('|', [$link->severity, $link->linktype, $link->url, $link->message]);
            $groups[$key][] = $link;
        }
        return array_values($groups);
    }
}
