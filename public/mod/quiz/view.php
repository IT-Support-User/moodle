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
 * This page is the entry page into the quiz UI. Displays information about the
 * quiz to students and teachers, and lets students see their previous attempts.
 *
 * @package   mod_quiz
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use mod_quiz\access_manager;
use mod_quiz\output\list_of_attempts;
use mod_quiz\output\renderer;
use mod_quiz\output\view_page;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->dirroot.'/mod/quiz/locallib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/course/format/lib.php');

$id = optional_param('id', 0, PARAM_INT); // Course Module ID, or ...
$q = optional_param('q',  0, PARAM_INT);  // Quiz ID.

if ($id) {
    $quizobj = quiz_settings::create_for_cmid($id, $USER->id);
} else {
    $quizobj = quiz_settings::create($q, $USER->id);
}
$quiz = $quizobj->get_quiz();
$cm = $quizobj->get_cm();
$course = $quizobj->get_course();

// Check login and get context.
require_login($course, false, $cm);
$context = $quizobj->get_context();
require_capability('mod/quiz:view', $context);

// Cache some other capabilities we use several times.
$canattempt = has_capability('mod/quiz:attempt', $context);
$canreviewmine = has_capability('mod/quiz:reviewmyattempts', $context);
$canpreview = has_capability('mod/quiz:preview', $context);

// Create an object to manage all the other (non-roles) access rules.
$timenow = time();
$accessmanager = new access_manager($quizobj, $timenow,
        has_capability('mod/quiz:ignoretimelimits', $context, null, false));

// Trigger course_module_viewed event and completion.
quiz_view($quiz, $course, $cm, $context);

// Initialize $PAGE, compute blocks.
$PAGE->set_url('/mod/quiz/view.php', ['id' => $cm->id]);
// On the quiz view page, the browser back/forwards buttons should force a reload.
$PAGE->set_cacheable(false);

// Create view object which collects all the information the renderer will need.
$viewobj = new view_page();
$viewobj->accessmanager = $accessmanager;
$viewobj->canreviewmine = $canreviewmine || $canpreview;

// Get this user's attempts.
$attempts = quiz_get_user_attempts($quiz->id, $USER->id, 'finished', true);
$lastfinishedattempt = end($attempts);
$unfinished = false;
$unfinishedattemptid = null;
if ($unfinishedattempt = quiz_get_user_attempt_unfinished($quiz->id, $USER->id)) {
    $attempts[] = $unfinishedattempt;

    // If the attempt is now overdue, deal with that - and pass isonline = false.
    // We want the student notified in this case.
    $quizobj->create_attempt_object($unfinishedattempt)->handle_if_time_expired(time(), false);

    $unfinished = $unfinishedattempt->state == quiz_attempt::IN_PROGRESS ||
            $unfinishedattempt->state == quiz_attempt::OVERDUE;
    if (!$unfinished) {
        $lastfinishedattempt = $unfinishedattempt;
    }
    $unfinishedattemptid = $unfinishedattempt->id;
    $unfinishedattempt = null; // To make it clear we do not use this again.
}
$numattempts = count($attempts);

$gradeitemmarks = $quizobj->get_grade_calculator()->compute_grade_item_totals_for_attempts(
    array_column($attempts, 'uniqueid'));

$viewobj->attempts = $attempts;
$viewobj->attemptobjs = [];
foreach ($attempts as $attempt) {
    $attemptobj = new quiz_attempt($attempt, $quiz, $cm, $course, false);
    $attemptobj->set_grade_item_totals($gradeitemmarks[$attempt->uniqueid]);
    $viewobj->attemptobjs[] = $attemptobj;

}
$viewobj->attemptslist = new list_of_attempts($timenow);
foreach (array_reverse($viewobj->attemptobjs) as $attemptobj) {
    $viewobj->attemptslist->add_attempt($attemptobj);
}

// Work out the final grade, checking whether it was overridden in the gradebook.
// First, get an initial grade to display.
if (!$canpreview) {
    $mygrade = quiz_get_best_grade($quiz, $USER->id);
} else if ($lastfinishedattempt) {
    // Users who can preview the quiz don't get a proper grade, so work out a
    // plausible value to display instead, so the page looks right.
    $mygrade = quiz_rescale_grade($lastfinishedattempt->sumgrades, $quiz, false);
} else {
    $mygrade = null;
}

// Now, check the grade in the gradebook, if there is one.
$mygradeoverridden = false;
$gradebookfeedback = '';

$gradeitem = grade_item::fetch([
    'itemtype' => 'mod',
    'itemmodule' => 'quiz',
    'iteminstance' => $quiz->id,
    'itemnumber' => 0,
    'courseid' => $course->id,
]);

// If there's a grade item grade, then get that grade for this user.
// Users who can preview the quiz (eg teachers) won't have a proper grade,
// so no point getting their grades here.
if (!$canpreview && $gradeitem) {
    $grade = $gradeitem->get_grade($USER->id, false);
    $mygrade = $grade->finalgrade; // Use this grade to display in the view page.

    if ($grade->overridden) {
        if ($gradeitem->needsupdate) {
            // It is Error, but let's be consistent with the old code.
            $mygrade = 0;
        }
        $mygradeoverridden = true;
    }

    if (!empty($grade->feedback)) {
        $gradebookfeedback = $grade->feedback;
    }
}

$title = $course->shortname . ': ' . format_string($quiz->name);
$PAGE->set_title($title);
$PAGE->set_heading($course->fullname);
if (html_is_blank($quiz->intro)) {
    $PAGE->activityheader->set_description('');
}
$PAGE->add_body_class('limitedwidth');
/** @var renderer $output */
$output = $PAGE->get_renderer('mod_quiz');

// Print overall stats and table with existing attempts.
if ($attempts) {
    // Work out which columns we need, taking account what data is available in each attempt.
    list($someoptions, $alloptions) = quiz_get_combined_reviewoptions($quiz, $attempts);

    $viewobj->attemptcolumn  = $quiz->attempts != 1;

    $viewobj->gradecolumn    = $someoptions->marks >= question_display_options::MARK_AND_MAX &&
            quiz_has_grades($quiz);
    $viewobj->markcolumn     = $viewobj->gradecolumn && ($quiz->grade != $quiz->sumgrades);
    $viewobj->overallstats   = $lastfinishedattempt && $alloptions->marks >= question_display_options::MARK_AND_MAX;

    $viewobj->feedbackcolumn = quiz_has_feedback($quiz) && $alloptions->overallfeedback;
}

$viewobj->timenow = $timenow;
$viewobj->numattempts = $numattempts;
$viewobj->mygrade = $mygrade;
$viewobj->moreattempts = $unfinished ||
        !$accessmanager->is_finished($numattempts, $lastfinishedattempt);
$viewobj->mygradeoverridden = $mygradeoverridden;
$viewobj->gradebookfeedback = $gradebookfeedback;
$viewobj->lastfinishedattempt = $lastfinishedattempt;
$viewobj->canedit = has_capability('mod/quiz:manage', $context);
$viewobj->editurl = new moodle_url('/mod/quiz/edit.php', ['cmid' => $cm->id]);
$viewobj->backtocourseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
$viewobj->startattempturl = $quizobj->start_attempt_url();

if ($accessmanager->is_preflight_check_required($unfinishedattemptid)) {
    $viewobj->preflightcheckform = $accessmanager->get_preflight_check_form(
            $viewobj->startattempturl, $unfinishedattemptid);
}
$viewobj->popuprequired = $accessmanager->attempt_must_be_in_popup();
$viewobj->popupoptions = $accessmanager->get_popup_options();

// Display information about this quiz.
$viewobj->infomessages = $viewobj->accessmanager->describe_rules();
if ($quiz->attempts != 1) {
    $viewobj->infomessages[] = get_string('gradingmethod', 'quiz',
            quiz_get_grading_option_name($quiz->grademethod));
}

// Inform user of the grade to pass if non-zero.
if ($gradeitem && grade_floats_different($gradeitem->gradepass, 0)) {
    $a = new stdClass();
    $a->grade = quiz_format_grade($quiz, $gradeitem->gradepass);
    $a->maxgrade = quiz_format_grade($quiz, $quiz->grade);
    $viewobj->infomessages[] = get_string('gradetopassoutof', 'quiz', $a);
}

// Determine whether a start attempt button should be displayed.
$viewobj->quizhasquestions = $quizobj->has_questions();
$viewobj->preventmessages = [];
if (!$viewobj->quizhasquestions) {
    $viewobj->buttontext = '';

} else {
    if ($unfinished) {
        if ($canpreview) {
            $viewobj->buttontext = get_string('continuepreview', 'quiz');
        } else if ($canattempt) {
            $viewobj->buttontext = get_string('continueattemptquiz', 'quiz');
        }
    } else {
        if ($canpreview) {
            $viewobj->buttontext = get_string('previewquizstart', 'quiz');
        } else if ($canattempt) {
            $viewobj->preventmessages = $viewobj->accessmanager->prevent_new_attempt(
                    $viewobj->numattempts, $viewobj->lastfinishedattempt);
            if ($viewobj->preventmessages) {
                $viewobj->buttontext = '';
            } else if ($viewobj->numattempts == 0) {
                $viewobj->buttontext = get_string('attemptquiz', 'quiz');
            } else {
                $viewobj->buttontext = get_string('reattemptquiz', 'quiz');
            }
        }
    }

    // Users who can preview the quiz should be able to see all messages for not being able to access the quiz.
    if ($canpreview) {
        $viewobj->preventmessages = $viewobj->accessmanager->prevent_access();
    } else if ($viewobj->buttontext) {
        // If, so far, we think a button should be printed, so check if they will be allowed to access it.
        if (!$viewobj->moreattempts) {
            $viewobj->buttontext = '';
        } else if ($canattempt) {
            $viewobj->preventmessages = $viewobj->accessmanager->prevent_access();
            if ($viewobj->preventmessages) {
                $viewobj->buttontext = '';
            }
        }
    }

    // If the quiz has any invalid questions, we cannot attempt it.
    if (in_array('missingtype', $quizobj->get_all_question_types_used())) {
        $viewobj->preventmessages[] = $OUTPUT->notification(
            get_string('quizinvalidquestions', 'mod_quiz'), notification::NOTIFY_ERROR, false);
        $viewobj->buttontext = '';
    }
}

$viewobj->showbacktocourse = ($viewobj->buttontext === '' &&
        course_get_format($course)->has_view_page());

echo $OUTPUT->header();

if (!empty($gradinginfo->errors)) {
    foreach ($gradinginfo->errors as $error) {
        $errortext = new notification($error, notification::NOTIFY_ERROR);
        echo $OUTPUT->render($errortext);
    }
}

if (isguestuser()) {
    // Guests can't do a quiz, so offer them a choice of logging in or going back.
    echo $output->view_page_guest($course, $quiz, $cm, $context, $viewobj->infomessages, $viewobj);
} else if (!isguestuser() && !($canattempt || $canpreview
          || $viewobj->canreviewmine)) {
    // If they are not enrolled in this course in a good enough role, tell them to enrol.
    echo $output->view_page_notenrolled($course, $quiz, $cm, $context, $viewobj->infomessages, $viewobj);
} else {
    echo $output->view_page($course, $quiz, $cm, $context, $viewobj);
}

echo $OUTPUT->footer();
