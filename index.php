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
 * @package local_metadata
 * @author Mike Churchward <mike.churchward@poetgroup.org>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright 2016 The POET Group
 */

require('../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->dirroot.'/local/metadata/lib.php');
require_once($CFG->dirroot.'/local/metadata/definelib.php');

$action   = optional_param('action', '', PARAM_ALPHA);
$contextlevel = optional_param('contextlevel', CONTEXT_USER, PARAM_INT);
$pages = [CONTEXT_USER => 'user', CONTEXT_COURSE => 'course', CONTEXT_MODULE => 'module'];
$redirect = $CFG->wwwroot.'/local/metadata/index.php?contextlevel='.$contextlevel;

$strchangessaved    = get_string('changessaved');
$strcancelled       = get_string('cancelled');
$strdefaultcategory = get_string('profiledefaultcategory', 'admin');
$strnofields        = get_string('profilenofieldsdefined', 'admin');
$strcreatefield     = get_string('profilecreatefield', 'admin');

if (($action == 'coursedata') || ($action == 'moduledata')) {
    require_login();
} else {
    admin_externalpage_setup('metadata'.$pages[$contextlevel]);
}

// Do we have any actions to perform before printing the header.

switch ($action) {
    case 'movecategory':
        $id  = required_param('id', PARAM_INT);
        $dir = required_param('dir', PARAM_ALPHA);

        if (confirm_sesskey()) {
            local_metadata_move_category($id, $dir);
        }
        redirect($redirect);
        break;
    case 'movefield':
        $id  = required_param('id', PARAM_INT);
        $dir = required_param('dir', PARAM_ALPHA);

        if (confirm_sesskey()) {
            local_metadata_move_field($id, $dir);
        }
        redirect($redirect);
        break;
    case 'deletecategory':
        $id      = required_param('id', PARAM_INT);
        if (confirm_sesskey()) {
            local_metadata_delete_category($id);
        }
        redirect($redirect, get_string('deleted'));
        break;
    case 'deletefield':
        $id      = required_param('id', PARAM_INT);
        $confirm = optional_param('confirm', 0, PARAM_BOOL);

        // If no userdata for profile than don't show confirmation.
        $datacount = $DB->count_records('local_metadata', ['fieldid' => $id]);
        if (((data_submitted() && $confirm) || ($datacount === 0)) && confirm_sesskey()) {
            local_metadata_delete_field($id);
            redirect($redirect, get_string('deleted'));
        }

        // Ask for confirmation, as there is user data available for field.
        $fieldname = $DB->get_field('local_metadata_field', 'name', ['id' => $id]);
        $optionsyes = ['id' => $id, 'confirm' => 1, 'action' => 'deletefield', 'sesskey' => sesskey()];
        $strheading = get_string('profiledeletefield', 'admin', format_string($fieldname));
        $PAGE->navbar->add($strheading);
        echo $OUTPUT->header();
        echo $OUTPUT->heading($strheading);
        $formcontinue = new single_button(new moodle_url($redirect, $optionsyes), get_string('yes'), 'post');
        $formcancel = new single_button(new moodle_url($redirect), get_string('no'), 'get');
        echo $OUTPUT->confirm(get_string('profileconfirmfielddeletion', 'admin', $datacount), $formcontinue, $formcancel);
        echo $OUTPUT->footer();
        die;
        break;
    case 'editfield':
        $id       = optional_param('id', 0, PARAM_INT);
        $datatype = optional_param('datatype', '', PARAM_ALPHA);

        local_metadata_edit_field($id, $datatype, $redirect, $contextlevel);
        die;
        break;
    case 'editcategory':
        $id = optional_param('id', 0, PARAM_INT);

        local_metadata_edit_category($id, $redirect, $contextlevel);
        die;
        break;

    case 'coursedata':
    case 'moduledata':
    case 'userdata':
        $instanceid = required_param('id', PARAM_INT);
        if ($action == 'coursedata') {
            $instance = $DB->get_record('course', ['id' => $instanceid], '*', MUST_EXIST);
            $layout = 'admin';
            $context = context_course::instance($instanceid);
            $redirect = new \moodle_url('/course/view.php', ['id' => $instanceid]);
            require_login($instance);
            require_capability('moodle/course:create', $context);
        } else if ($action == 'moduledata') {
            $cmsql = 'SELECT cm.*, m.name ' .
                     'FROM {course_modules} cm ' .
                     'INNER JOIN {modules} m ON cm.module = m.id ' .
                     'WHERE cm.id = ?';
            if (!($instance = $DB->get_record_sql($cmsql, ['id' => $instanceid], MUST_EXIST))) {
                print_error('invalidcoursemodule');
            }
            $layout = 'incourse';
            $context = context_module::instance($instanceid);
            $redirect = new \moodle_url('/mod/'.$instance->name.'/view.php', ['id' => $instanceid]);
            require_login($instance->course, true, $instance);
            require_capability('moodle/course:manageactivities', $context);
        } else if ($action == 'userdata') {
            $instance = $DB->get_record('user', ['id' => $instanceid], '*', MUST_EXIST);
            $layout = 'admin';
            $context = context_user::instance($instanceid);
            $redirect = new \moodle_url('/user/preferences.php', ['userid' => $instanceid]);
            require_login();
            require_capability('moodle/user:editprofile', $context);
        } else {
            break;
        }

        $PAGE->set_url('/local/metadata/index.php',
            ['contextlevel' => $contextlevel, 'id' => $instanceid, 'action' => $action]);
        $PAGE->set_pagelayout($layout);
        $PAGE->set_context($context);

        // Add the metadata to the object.
        local_metadata_load_data($instance, $contextlevel);
        $dataclass = "\\local_metadata\\output\\{$pages[$contextlevel]}\\manage_data";
        $formclass = "\\local_metadata\\output\\{$pages[$contextlevel]}\\manage_data_form";
        $dataoutput = new $dataclass($instance, $contextlevel, $action);
        $dataform = new $formclass(null, $dataoutput);
        $dataoutput->add_form($dataform);

        // Handle form data.
        if ($dataform->is_cancelled()) {
            redirect($redirect);
        } else if (!($data = $dataform->get_data())) {
            $output = $PAGE->get_renderer('local_metadata', $pages[$contextlevel]);
            echo $output->render($dataoutput);
        } else {
            local_metadata_save_data($data, $contextlevel);
            $output = $PAGE->get_renderer('local_metadata', $pages[$contextlevel]);
            $dataoutput->set_saved();
            echo $output->render($dataoutput);
        }
        die;
        break;

    default:
        // Normal form.
}

// Show all categories.
$categories = $DB->get_records('local_metadata_category', ['contextlevel' => $contextlevel], 'sortorder ASC');

// Check that we have at least one category defined.
if (empty($categories)) {
    $defaultcategory = new stdClass();
    $defaultcategory->contextlevel = $contextlevel;
    $defaultcategory->name = $strdefaultcategory;
    $defaultcategory->sortorder = 1;
    $DB->insert_record('local_metadata_category', $defaultcategory);
    redirect($redirect);
}

$PAGE->set_url($CFG->wwwroot.'/local/metadata/index.php', ['contextlevel' => $contextlevel]);
$output = $PAGE->get_renderer('local_metadata', $pages[$contextlevel]);
// Print the header.
echo $output->header();
echo $output->heading(get_string($pages[$contextlevel].'metadata', 'local_metadata'));

echo $output->render(new \local_metadata\output\category_table($categories));
echo $output->render(new \local_metadata\output\data_creation($contextlevel));

echo $output->footer();
die;