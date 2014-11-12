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
 * Strings for component 'assignsubmission_review', language 'en'
 *
 * @package   assignsubmission_review
 * @copyright 2014 Larry Broda<lbroda@illinois.edu>/Nate Baxley<nbaxley@illinois.edu>/University of Illinois at Urbana Champaign {@link http://illinois.edu}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'UI Activity Grader submissions';
$string['review'] = 'UI Activity Grader';
$string['allowreviewsubmissions'] = 'Enabled';
$string['default'] = 'Enabled by default';
$string['default_help'] = 'If set, this submission method will be enabled by default for all new assignments.';
$string['enabled'] = 'UI Activity Grader';

$string['enabled_help'] = 'If enabled, students will be graded based on participation in an activity such as a forum.
To enable Activity Grader:
<ul>
<li>Select "Yes" in the UI Activity Grader pull-down</li>
<li>Select an Activity Type from the pull-down menu</li>
<li>Select a particular subject (e.g. forum name) for the activity</li>
<li>Since Activity Grader assignments are not submitted in the usual way, some of the settings 
in the "Assignment settings" block which relate to submissions will not apply.</li>
<li>For tips on setting up grading, click the help (?) icon next to <em>"Grading Tips"</em></li>
</ul>';



// $string['enabled_help'] = 'If enabled, students will be graded based on participation in a forum or wiki';
$string['activitytype'] = 'Activity Type';
$string['activitytype_help'] = 'The type of activity (e.g. forum) which will be graded.<br />If UI Activity Grader submissions are enabled, this is required.';

//
$string['reviewgradingmethod'] = 'Activity Grading Method';
$string['reviewgradingmethod_help'] = 'How the activity will be graded';

$string['reviewforumgradingmethod'] = 'Forum Grading Method';
$string['reviewforumgradingmethod_help'] = 'How forum participation will be graded';
$string['reviewwikigradingmethod'] = 'Wiki Grading Method';
$string['reviewwikigradingmethod_help'] = 'How wiki participation will be graded';


$string['reviewminpost'] = "Minimum Posts";
$string['reviewminpost_help'] = "The minimum number of posts required for full credit";
$string['reviewminreply'] = "Minimum Replies";
$string['reviewminreply_help'] = "The minimum number of replies required for full credit";

$string['forumsubject'] = 'Forum Name';
$string['forumsubject_help'] = 'Choose the forum which will be graded';

$string['wikisubject'] = 'Wiki Name';
$string['wikisubject_help'] = 'Choose the Wiki which will be graded';

$string['posts'] = 'Posts';
$string['replies'] = 'Replies';

$string['post_count'] = 'Post Count';
$string['post_grade'] = 'Post Grade';
$string['reply_count'] = 'Reply Count';
$string['reply_grade'] = 'Reply Grade';
$string['posts_gradevalue'] = 'Posts Grade';        
$string['posts_min'] = 'Minimum Posts';     
$string['posts_duedate'] = 'Posts Due Date';        
$string['reply_gradevalue'] = 'Replies Grade';      
$string['reply_min'] = 'Minimum Replies';       
$string['reply_duedate'] = 'Replies Due Date';

$string['nosubmission'] = 'Nothing has been submitted for this assignment';
$string['activity'] = 'UI Activity Grader';
$string['activityfilename'] = 'activity.html';
$string['activitysubmission'] = 'Allow UI Activity Grader';

$strings['forumgradingmethod'] = 'Forum Grading Method'; // e.g., total posts, posts+replies, etc
$strings['wikigradingmethod'] = 'Forum Grading Method'; // e.g. comments, etc

$string['activitytypewarning'] = 'To disable Activity Grader, Select "No" above;<br />otherwise, a valid activity type is required';

$string['gradingformtips'] = 'Click help icon (?) for tips on setting up grading';
$string['gradingform'] = 'Setting up Grading for Activity Grader Assignments';
$string['gradingform_help'] = '<ul>
<li>In the <em>Grade</em> section of the assignment form (below), choose
a grade value for the assignment.</li>
<li>For <em>Grading Method</em>, choose "Marking Guide".
<li>When finished with the assignment settings form, click on <em>"Save and display"</em></li>
<li>On the following page, choose "Create new grading form from a template"</li>
<li>After choosing a form, you should be offered the opportunity to edit it.  Click on this, and
edit your chosen form so that the total grade values for all criteria equal the assignment grade value
you set.</li>
<li>If are not offered the choice of setting up the form, or if you later wish to edit your grading form, 
choose <em>"Advanced Grading"</em> from the <em>Assignment administration</em> menu</li>
<li>You may use one of the other grading methods if you wish (and are familiar with Moodle grading methods)</li>

</ul>';

$string['assignmenttype'] = 'Assignment type: ';
$string['forumname'] = 'Forum name';
$string['noposts'] = 'You have not yet posted to this forum';
$string['newposts'] = 'new posts for you were found';

$string['postsandreplies'] = 'Posts and Replies';
$string['totalposts'] = 'Total Posts';

/* wikis not enabled yet, so only mention forums not existing */
$string['available_activities'] = 'forums or wikis';
$string['available_activities'] = 'forums';

$string['noactivities'] = 'There are no ' . $string['available_activities'] . ' available for this course; you must create one before<br />creating an Activity Grader assignment';

$string['nowikis'] = 'No wikis have been created for this course; wiki grading will not be available';
$string['noforums'] = 'No forums have been created for this course; forum grading will not be available';

$string['minrequired'] = 'Minimum required: ';
$string['forumsubmission'] = 'To submit work for this assignment, post to the forum as per the instructor\'s requirements;<br /> It is not necessary to use these submission pages.'; 
$string['clicktoviewfull'] = 'Click icon to display full submission';

$string['wasupgraded'] = 'Assignment was upgraded from old (2.2) type ';
$string['oldpostval'] = 'Old Posts grade value: ';
$string['oldreplyval'] = 'Old Replies grade value: ';
$string['toofewposts'] = 'Number of Posts ({$a->totpost}) is fewer than required minimum of {$a->pmin}';
$string['toofewreplies'] = 'Number of Replies ({$a->totrep}) is fewer than required minimum of {$a->rmin}';
$string['postsoverdue'] = '{$a->poverdue} of {$a->totpost} post(s) were past due';
$string['repliesoverdue'] = '{$a->roverdue} of {$a->totrep} replies were past due';
$string['upgradednote'] = 'Upgraded assignment. Grades from old record: Posts={$a->pgrade} Replies={$a->rgrade}';
$string['nosettings'] = 'No activity grader assignment settings found for this assignment';
$string['unknowntype'] = 'Unrecognized activity type';
$string['noinstance4update'] = 'update_counts called with no assignment instance';
$string['notenabled'] = 'update_counts: Activity Grader not enabled for assignment';
$string['noforumid'] = 'No forum id in assignment instance';
$string['badactivitytype'] = 'Unsupported/Unrecognized activity type';
$string['gradingtipslab'] = 'Grading Tips';
$string['gradingtips'] = 'How to set up grading for Activity Grader assignments';
$string['cannotgettype'] = 'cannot get activity type information for submission';
$string['forumhd'] = 'Forum';
$string['minlab'] = 'minimum';
$string['unimpsubmission'] = 'Activity type not implemented yet... something is amiss';

