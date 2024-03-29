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
 * @package    local_normalize_grades
 * @copyright  2019 onwards Robert Russo
 * @copyright  2019 onwards Louisiana State University, LSUOnline
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Get the required Moodle libraries.
require_once($CFG->libdir . '/grade/grade_item.php');
require_once($CFG->libdir . '/grade/grade_grade.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/grade/report/lib.php');

/*
 * Get grade report functions and variables from parent class in grade/report/lib.php
 *
 * @package    local_normalize_grades
 * @copyright  2008 onwards Louisiana State University, LSUOnline
 * @copyright  2008 onwards Robert Russo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_report_normalize_grades extends grade_report {

    /**
     * The user.
     * @var object $user
     */
    public $user;

    /**
     * The user's courses
     * @var array $courses
     */
    public $courses;

    /**
     * Show course/category totals if they contain hidden items
     * @var book $showtotalsifcontainhidden
     */
    public $showtotalsifcontainhidden;

    /**
     * An array of course ids that the user is a student in.
     * @var array $studentcourseids
     */
    public $studentcourseids;

    /**
     * An array of courses that the user is a teacher in.
     * @var array $teachercourses
     */
    public $teachercourses;

    /**
     * Constructor. Sets local copies of user preferences and initialises grade_tree.
     * Run for each course the user is enrolled in.
     * @param int $userid
     * @param int $courseid
     * @param string $context
     */
    public function __construct($userid, $courseid, $context) {
        global $CFG;
        parent::__construct($courseid, null, $context);

        // Get the user (for later use in grade/report/lib.php).
        $this->user = \core_user::get_user($userid);

        // Create an array (for later use in grade/report/lib.php).
        $this->showototalsifcontainhidden = array();

        // Sanity check.
        if ($courseid) {
            // This is problematic as the instructor can set these to be different.
            // Differing settings will result in different grades.
            // The admin is required to choose a report to key off.
            // If no report is chosen, default to the user report.
            // Instructor will see a HUGE error in their course if there is no consistency in report settings.
            $key = $CFG->normalize_grades_reportkey ? $CFG->normalize_grades_reportkey : 'user';
            if ($key == 'overview') {
                // Grabs the course specific overview report setting if exists, if not, grabs the system setting.
                $report = grade_get_setting(
                              $courseid,
                              'report_overview_showtotalsifcontainhidden',
                              $CFG->grade_report_overview_showtotalsifcontainhidden);
            } else {
                // Grabs the course specific user report setting if exists, if not, grabs the system setting.
                $report = grade_get_setting(
                              $courseid,
                              'report_user_showtotalsifcontainhidden',
                              $CFG->grade_report_user_showtotalsifcontainhidden);
            }
            $this->showtotalsifcontainhidden[$courseid] = $report;
        }
    }

    public static function get_course_setting($courseid) {
        global $CFG;
        // This is problematic as the instructor can set these to be different.
        // Differing settings will result in different grades.
        // The admin is required to choose a report to key off.
        // If no report is chosen, default to the user report.
        // Instructor will see a HUGE error in their course if there is no consistency in report settings.
        $key = $CFG->normalize_grades_reportkey ? $CFG->normalize_grades_reportkey : 'user';
        if ($key == 'overview') {
            // Grabs the course specific overview report setting if exists, if not, grabs the system setting.
            $report = grade_get_setting(
                          $courseid,
                          'report_overview_showtotalsifcontainhidden',
                          $CFG->grade_report_overview_showtotalsifcontainhidden);
        } else {
            // Grabs the course specific user report setting if exists, if not, grabs the system setting.
            $report = grade_get_setting(
                          $courseid,
                          'report_user_showtotalsifcontainhidden',
                          $CFG->grade_report_user_showtotalsifcontainhidden);
        }
        return $report;
    }

    public function process_action($target, $action) {
    }

    public function process_data($data) {
        return $this->screen->process($data);
    }

    public function get_blank_hidden_total_and_adjust_bounds($courseid, $coursetotalitem, $finalgrade) {
        return($this->blank_hidden_total_and_adjust_bounds($courseid, $coursetotalitem, $finalgrade));
    }
}



/*
 * Returns the formatted course total item value give a userid and a course id.
 * If a course has no course grade item (no grades at all) the system returns '-'.
 * If a user has no course grade, the system returns '-'.
 * If a user has grades and the instructor has hidden some of the users grades
 * and those hidden items impact the course grade based on the instructor's settings,
 * the system recalculates the course grade appropriately
 *
 * @var courseid
 * @var userid
 * @return array
 */
function ng_get_grade_for_course($courseid, $userid) {
    global $CFG, $DB;
    // Get the course total item for the course in question.
    $coursetotalitem = grade_item::fetch_course_item($courseid);

    // Set the course context.
    $coursecontext = context_course::instance($courseid);

    // Check to see if the user in question can view hidden grades.
    $canviewhidden = has_capability('moodle/grade:viewhidden', $coursecontext, $userid);

    // Instantiate the grade report.
    $report = new grade_report_normalize_grades($userid, $courseid, $coursecontext);

    // If there are no grades, return -.
    if (!$coursetotalitem) {
        $totalgrade = '-';
    }

    // Set up the grade parameters for future use.
    $gradegradeparams = array(
        'itemid' => $coursetotalitem->id,
        'userid' => $userid
    );

    // Instantiate the user grade.
    $usergradegrade = new grade_grade($gradegradeparams);
    $usergradegrade->grade_item =& $coursetotalitem;

    // Set the finalgrade value.
    $finalgrade = $usergradegrade->finalgrade;

    // If the user cannot view hidden grades and there is a grade, do more stuff.
    if (!$canviewhidden and !is_null($finalgrade)) {

        // Adjust the grade value based on hidden items and rules.
        $adjustedgrade = $report->get_blank_hidden_total_and_adjust_bounds($courseid,
                                                                           $coursetotalitem,
                                                                           $finalgrade);
        // Adjust this grade item - min and max are affected by the hidden values.
        $coursetotalitem->grademax = $adjustedgrade['grademax'];
        $coursetotalitem->grademin = $adjustedgrade['grademin'];

    } else if (!is_null($finalgrade)) {
        // Even if the user can view hidden grades, calculate them based on course rules.
        $adjustedgrade['grade'] = $finalgrade;

        // Adjust this grade item - min and max are affected by the hidden values.
        $coursetotalitem->grademin = $usergradegrade->get_grade_min();
        $coursetotalitem->grademax = $usergradegrade->get_grade_max();
    }

    // Sanity check. If we have an adjusted grade, do more stuff.
    if (isset($adjustedgrade['grade'])) {
        // Get the grade the student sees.
        $calculatedgrade = grade_format_gradevalue($adjustedgrade['grade'], $coursetotalitem, true);
        // Get the numeric grade.
        $numericgrade = grade_format_gradevalue(
                            $adjustedgrade['grade'],
                            $coursetotalitem,
                            true,
                            GRADE_DISPLAY_TYPE_REAL,
                            $coursetotalitem->decimals);
        // Get the percent grade.
        $percentgrade = grade_format_gradevalue(
                            $adjustedgrade['grade'],
                            $coursetotalitem,
                            true,
                            GRADE_DISPLAY_TYPE_PERCENTAGE,
                            $coursetotalitem->decimals);
        // Get the letter grade.
        $lettergrade = grade_format_gradevalue(
                           $adjustedgrade['grade'],
                           $coursetotalitem,
                           true,
                           GRADE_DISPLAY_TYPE_LETTER,
                           $coursetotalitem->decimals);

        // If there is no grade, set the value to null.
    } else {
        $calculatedgrade = null;
        $numericgrade = null;
        $percentgrade = null;
        $lettergrade = null;
    }

    // Set up the total grade object.
    $totalgrade = new \stdClass();
    // Set the name of the table.
    $dbtable = 'normalize_grades';

    // Get the value of the course setting and use it later.
    $moodlecoursesetting = grade_report_normalize_grades::get_course_setting($courseid);
    $limiter = $courseid . " " . $userid . " " . $coursetotalitem->id;
    $storedsetting = $DB->get_record('normalize_grades', array('limiter' => $limiter), 'storedsetting');

    // Get the grade_grade item so we can use its ID and timemodified data.
    $gg = $DB->get_record('grade_grades', array('itemid' => $coursetotalitem->id, 'userid' => $userid), '*', IGNORE_MISSING);

    // Set the values appropriately so we can store them and not calculate them again.
    $totalgrade->limiter = $courseid . " " . $userid . " " . $coursetotalitem->id;
    $totalgrade->courseid = $courseid;
    $totalgrade->userid = $userid;
    $totalgrade->itemid = $coursetotalitem->id;
    $totalgrade->gradeid = $gg->id;
    $totalgrade->originalgrade = $finalgrade;
    $totalgrade->calculatedgrade = $calculatedgrade;
    $totalgrade->numericgrade = $numericgrade;
    $totalgrade->percentgrade = $percentgrade;
    $totalgrade->lettergrade = $lettergrade;
    $totalgrade->storedsetting = $moodlecoursesetting;
    $totalgrade->timemodified = $gg->timemodified;

    // Check to make sure we do not have an entry matching the course/user/itemid with a matching grade.
    if ($DB->record_exists($dbtable, array('limiter' => $totalgrade->limiter))) {
        if ($DB->record_exists($dbtable, array(
                                            'limiter' => $totalgrade->limiter,
                                            'originalgrade' => $totalgrade->originalgrade,
                                            'calculatedgrade' => $totalgrade->calculatedgrade,
                                            'numericgrade' => $totalgrade->numericgrade,
                                            'percentgrade' => $totalgrade->percentgrade,
                                            'lettergrade' => $totalgrade->lettergrade,
                                            'storedsetting' => $moodlecoursesetting,
                                            'timemodified' => $totalgrade->timemodified))) {
            return;
        } else {
            // A mismatched record exists for this course/user/itemid, update it.
            // The method of calculating student grades has, update it.
            // Grab the record in question so we can grab its ID for updating.
            $dataid = $DB->get_record($dbtable, array('limiter' => $totalgrade->limiter), '*', IGNORE_MISSING);
            // Set the ID as part of the totalgrade object.
            $totalgrade->id = $dataid->id;
            // Update the record.
            $DB->update_record($dbtable, $totalgrade);
        }
    } else {
        // No record exists for this course/user/itemid, create it.
        $DB->insert_record($dbtable, $totalgrade, $returnid = true, $bulk = false);
    }
    // Return the course total grade object with formatted values for the user.
    return $totalgrade;
}

function ng_grade_formats($course, $user) {
    global $CFG;
    // Get the roles that are graded.
    $gradebookroles = explode(',', $CFG->gradebookroles);

    // Set up the course context.
    $coursecontext = context_course::instance($course->id);

    // Get the roles in the course.
    $rolesincourse = get_user_roles($coursecontext, $user->id, false);

    // Set up the course name for future use.
    $coursename = $course->shortname;
    // Ensure we get all graded roles.

    if (count($rolesincourse) > 1) {
        foreach ($rolesincourse as $roleincourse) {
            // We only care about the system defined roles that are graded.
            if (in_array($roleincourse->roleid, $gradebookroles)) {
                // Here is where we actually get the grade.
                ng_get_grade_for_course($course->id, $user->id);
            }
        }
    } else {
        $roleincourse = $rolesincourse ? array_pop($rolesincourse) : "GOATUser:" . $user->id . " - Course: " . $course->id;
        if (in_array($roleincourse->roleid, $gradebookroles)) {
            // Here is where we actually get the grade. If there is no grade, return 0..
            ng_get_grade_for_course($course->id, $user->id);
        }
    }
}
