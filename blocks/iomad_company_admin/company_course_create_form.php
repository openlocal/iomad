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
 * Script to let a user create a course for a particular company.
 */

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once('lib.php');
require_once(dirname(__FILE__) . '/../../course/lib.php');

class course_edit_form extends moodleform {
    protected $title = '';
    protected $description = '';
    protected $selectedcompany = 0;
    protected $context = null;

    public function __construct($actionurl, $companyid, $editoroptions) {
        global $CFG;

        $this->selectedcompany = $companyid;
        $this->context = get_context_instance(CONTEXT_COURSECAT, $CFG->defaultrequestcategory);
        $this->editoroptions = $editoroptions;

        parent::__construct($actionurl);
    }

    public function definition() {
        global $CFG;

        $mform =& $this->_form;

        // Then show the fields about where this block appears.
        $mform->addElement('header', 'header',
                            get_string('companycourse', 'block_iomad_company_admin'));

        $mform->addElement('text', 'fullname', get_string('fullnamecourse'),
                            'maxlength="254" size="50"');
        $mform->addHelpButton('fullname', 'fullnamecourse');
        $mform->addRule('fullname', get_string('missingfullname'), 'required', null, 'client');
        $mform->setType('fullname', PARAM_MULTILANG);

        $mform->addElement('text', 'shortname', get_string('shortnamecourse'),
                            'maxlength="100" size="20"');
        $mform->addHelpButton('shortname', 'shortnamecourse');
        $mform->addRule('shortname', get_string('missingshortname'), 'required', null, 'client');
        $mform->setType('shortname', PARAM_MULTILANG);

        // Create course as self enrolable.
        $select = &$mform->addElement('select', 'selfenrol',
                            get_string('selfenrolcoursetype', 'block_iomad_company_admin'),
                            array('No', 'Yes'));
        $mform->addHelpButton('selfenrol', 'selfenrolcourse', 'block_iomad_company_admin');
        $select->setSelected('no');

        $mform->addElement('editor', 'summary_editor',
                            get_string('coursesummary'), null, $this->editoroptions);
        $mform->addHelpButton('summary_editor', 'coursesummary');
        $mform->setType('summary_editor', PARAM_RAW);

        // Add action buttons.
        $buttonarray = array();
        $buttonarray[] = &$mform->createElement('submit', 'submitbutton',
                            get_string('createcourse', 'block_iomad_company_admin'));
        $buttonarray[] = &$mform->createElement('submit', 'submitandviewbutton',
                            get_string('createandvisitcourse', 'block_iomad_company_admin'));
        $buttonarray[] = &$mform->createElement('cancel');
        $mform->addGroup($buttonarray, 'buttonar', '', array(' '), false);
        $mform->closeHeaderBefore('buttonar');

    }

    public function get_data() {
        $data = parent::get_data();
        if ($data) {
            $data->title = '';
            $data->description = '';

            if ($this->title) {
                $data->title = $this->title;
            }

            if ($this->description) {
                $data->description = $this->description;
            }
        }
        return $data;
    }

    // Perform some extra moodle validation.
    public function validation($data, $files) {
        global $DB, $CFG;

        $errors = parent::validation($data, $files);
        if ($foundcourses = $DB->get_records('course', array('shortname' => $data['shortname']))) {
            if (!empty($data['id'])) {
                unset($foundcourses[$data['id']]);
            }
            if (!empty($foundcourses)) {
                foreach ($foundcourses as $foundcourse) {
                    $foundcoursenames[] = $foundcourse->fullname;
                }
                $foundcoursenamestring = implode(',', $foundcoursenames);
                $errors['shortname'] = get_string('shortnametaken', '', $foundcoursenamestring);
            }
        }

        return $errors;
    }

}

$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
$companyid = optional_param('companyid', 0, PARAM_INTEGER);

$context = get_context_instance(CONTEXT_SYSTEM);
require_login();
$PAGE->set_context($context);

require_capability('block/iomad_company_admin:createcourse', $context);

$urlparams = array('companyid' => $companyid);
if ($returnurl) {
    $urlparams['returnurl'] = $returnurl;
}
$companylist = new moodle_url('/local/iomad_dashboard/index.php', $urlparams);

// Correct the navbar.
// Set the name for the page.
$linktext = get_string('createcourse', 'block_iomad_company_admin');

// Set the url.
$linkurl = new moodle_url('/blocks/iomad_company_admin/company_course_create_form.php');

// Build the nav bar.
company_admin_fix_breadcrumb($PAGE, $linktext, $linkurl);

$blockpage = new blockpage($PAGE, $OUTPUT, 'iomad_company_admin', 'block', 'createcourse_title');
$blockpage->setup($urlparams);

// Set the company ID.
if (!empty($SESSION->currenteditingcompany)) {
    $companyid = $SESSION->currenteditingcompany;
} else if (!empty($USER->company)) {
    $companyid = company_user::companyid();
} else if (!has_capability('block/iomad_company_admin:edit_departments', $context)) {
    print_error('There has been a configuration error, please contact the site administrator');
} else {
    redirect(new moodle_url('/local/iomad_dashboard/index.php'),
                             get_string('pleaseselect', 'block_iomad_company_admin'));
}


/* next line copied from /course/edit.php */
$editoroptions = array('maxfiles' => EDITOR_UNLIMITED_FILES,
                       'maxbytes' => $CFG->maxbytes,
                       'trusttext' => false,
                       'noclean' => true);

$mform = new course_edit_form($PAGE->url, $companyid, $editoroptions);

if ($mform->is_cancelled()) {
    redirect($companylist);

} else if ($data = $mform->get_data()) {

    $data->userid = $USER->id;

    // Merge data with course defaults.
    $company = $DB->get_record('company', array('id' => $companyid));
    if (!empty($company->category)) {
        $data->category = $company->category;
    } else {
        $data->category = $CFG->defaultrequestcategory;
    }
    $courseconfig = get_config('moodlecourse');
    $mergeddata = (object) array_merge((array) $courseconfig, (array) $data);

    // Turn on restricted modules.
    $mergeddata->restrictmodules = 1;

    if (!$course = create_course($mergeddata, $editoroptions)) {
        $this->verbose("Error inserting a new course in the database!");
        if (!$this->get('ignore_errors')) {
            die();
        }
    }

    // If licensed course, turn off all enrolments apart from license enrolment as
    // default  Moving this to a separate page.
    if ($data->selfenrol) {
        if ($instances = $DB->get_records('enrol', array('courseid' => $course->id))) {
            foreach ($instances as $instance) {
                $updateinstance = (array) $instance;
                if ($instance->enrol == 'self') {
                    $updateinstance['status'] = 0;
                } else if ($instance->enrol == 'license') {
                    $updateinstance['status'] = 1;
                } else if ($instance->enrol == 'manual') {
                    $updateinstance['status'] = 0;
                }
                $DB->update_record('enrol', $updateinstance);
            }
        }
    }

    // Associate the company with the course.
    $company = new company($companyid);
    // Check if we are a company manager.
    if ($DB->get_record('company_users', array('companyid' => $companyid,
                                                   'userid' => $USER->id,
                                                   'managertype' => 1))) {
        $company->add_course($course, 0, true);
    } else {
        $company->add_course($course);
    }

    if (isset($data->submitandviewbutton)) {
        // We are going to the course instead.
        redirect(new moodle_url('/course/view.php', array('id' => $course->id)));
    } else {
        redirect($companylist);
    }
} else {

    $blockpage->display_header();

    $mform->display();

    echo $OUTPUT->footer();
}
