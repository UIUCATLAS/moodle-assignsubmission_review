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
 * This file contains the definition for the library class for UI Activity Grader submission plugin 
 *
 * This class provides all the functionality for the new assign module.
 *
 * @package assignsubmission_review
 * @copyright 2014 Larry Broda<lbroda@illinois.edu>/Nate Baxley<nbaxley@illinois.edu>/University of Illinois at Urbana Champaign {@link http://illinois.edu}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
/**
 * File area for review submission assignment
 * not really applicable but they all have it
 */
define('ASSIGNSUBMISSION_REVIEW_FILEAREA', 'submissions_review');

define('ASSIGNSUBMISSION_REVIEW_TYPE_FORUM', 'forum');
define('ASSIGNSUBMISSION_REVIEW_TYPE_WIKI', 'wiki');
define('ASSIGNSUBMISSION_REVIEW_METHOD_TOTALPOSTS', 1);
define('ASSIGNSUBMISSION_REVIEW_METHOD_POSTSREPLIES', 2);

define('ASSIGNSUBMISSION_REVIEW_UPDATE_DELAY', 5);

/* when did we last update post counts? start with never for this instance/refresh/etc, then
 * use this setting to avoid doing again unecessarily  */
$ASSIGNSUBMISSION_LAST_UPDATE_COUNTS = 0; 

/**
 * library class for review submission plugin extending submission plugin base class
 *
 * @package assignsubmission_review
 * @copyright 2013 University of Illinois
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_submission_review extends assign_submission_plugin {

    /**
     * Get the name of the review submission plugin
     * @return string
     */


    public function get_name() {

      return get_string('review', 'assignsubmission_review');
    }



    /* do some preprocessing in the is_enabled function
       before deferring to the parent function.  This way we can call update_counts
       and collect current submission info.
       We also check the grading method for upgraded assignments,
    */
    public function is_enabled() 
    {
      global $DB, $CFG, $COURSE;


	 if ($enabled = parent::is_enabled())
	{
	  /* kludgy way to do some pre-checking that it will be
	     too late for later on */
	  if ($this->assignment->has_instance()) 
	    {
	      /* this is a kludge so that update_counts can be run to create submissions records
		 when none have been created yet, so that they will show up on the 
		 assignment summary page for the teacher. 
		 The timer mechanism in update_counts will suppress redundant 
		 processing during this run */
	      $this->update_counts();

		/* second kludge is to reset the grading method on freshly upgraded assignments */
		if ($this->get_config('check_method')) 
		  {
		    /* check whether this is somebody whose role has
		       permission to set up the marking guide */
		    $course_context = context_course::instance($COURSE->id);
		    if (has_capability('moodle/grade:managegradingforms', $course_context))   {

		      require_once($CFG->dirroot.'/grade/grading/lib.php');
		      /* make sure grading is set to guide */
		      $gradingmanager = get_grading_manager($this->assignment->get_context(), 'mod_assign', 'submissions');

		      if ($gradingmanager->get_active_method() != 'guide') 
			{
			  $gradingmanager->set_active_method('guide');
			  $this->set_config('check_method', 0); /* don't do this check again */
			  /* redirect to page to choose marking guide templates */
			  $manage_url = $gradingmanager->get_management_url();
			  $manage_url->params(array('returnurl' => qualified_me()));
			  redirect($manage_url);
			}else
			{
			  $this->set_config('check_method', 0);
			}

		    }
		    
		  }
	    }
	  }
      return($enabled);	
    }
    
    


    /*   for an activity grader  assignment, get basic info on the
	 of the forum/wiki, etc which we are using as the subject
	 of the assignment.
	 return object with first element 'activitytype' => 'forum'|'wiki', etc,
	 and then adding name and intro
    */

    private function get_subject_info($rev_instance = null) 
    {
      global $DB, $COURSE;

      if (! $rev_instance) 
	{
	  if (! ($rev_instance = $this->get_config()))
	    {
	        print_error(get_string('nosettings', 'assignsubmission_review'));
         }
	 }  
      
      if (! $subjectid = $rev_instance->subjectid)
	{
	  return(null);
	}
      if (! $activity_type = $rev_instance->activitytype) 
	{
	  return(null);
	}

      $courseid = $COURSE->id;

      if ($activity_type == ASSIGNSUBMISSION_REVIEW_TYPE_FORUM) 
	{
	  $subinfo = $DB->get_record('forum', array('id' => $subjectid, 'course' => $courseid), 'name, intro');
	}else if ($activity_type == ASSIGNSUBMISSION_REVIEW_TYPE_WIKI) 
	{
	  $subinfo = $DB->get_record('wiki', array('id' => $subjectid, 'course' => $courseid), 'name, intro');
	}
      else
	{
	  print_error(get_string('unknowntype', 'assignsubmission_review'));
	} 
      
      if (is_object($subinfo)) 
	{
	  $subinfo->activitytype = $activity_type;
	  return($subinfo);
	}else
	{
	  return(null);
	}
    }
    
    
   /**
     * This function will update form post count data for  all students  in a forum type review
     assignment whether they have been graded or not.
     It will add the assign_submission and assignsubmission_review records if they
     do not yet exist.


     update_counts will not run if it determines that it has been run within the
     last ASSIGNSUBMISSION_REVIEW_UPDATE_DELAY seconds, unless $force is non-zero

     this will not carry from one mouseclick to the next, but it will save duplicate
     efforts which will otherwise occur within a single page instance.
     */



    private function update_counts($force=0) {
      global $CFG, $DB, $COURSE, $ASSIGNSUBMISSION_LAST_UPDATE_COUNTS;

      if (! $force) 
	{
	    /* won't be true first time, as last_update will be zero */
	  if ((time() - $ASSIGNSUBMISSION_LAST_UPDATE_COUNTS) < ASSIGNSUBMISSION_REVIEW_UPDATE_DELAY) 
	    {
	      /* ran very recently; don't need to run again */
	      return(true);
	    }
	}

      $ASSIGNSUBMISSION_LAST_UPDATE_COUNTS = time();
      
      // ignore userid for now.

      if (! $this->assignment->has_instance())
	{
	 print_error(get_string('noinstance4update', 'assignsubmission_review'));
	} 

      $assignment = $this->assignment->get_instance()->id;
      $review_instance = $this->get_config();
      if (! $review_instance->enabled) 
	{
	  print_error(get_string('notenabled', 'assignsubmission_review'));
	} 
      
      $activity_type = $review_instance->activitytype;
      
      if ($activity_type == ASSIGNSUBMISSION_REVIEW_TYPE_FORUM) 
	{
	  if (! isset($review_instance->subjectid)) 
	    {
	      print_error(get_string('noforumid', 'assignsubmission_review'));
	    }else
	    {
	      $forumid = $review_instance->subjectid;
	    }
	  
	  $curtime = time();
	  
	  // create submission records (where needed) for all users with posts;
	  $sql = "
			INSERT INTO {assign_submission}
			(assignment, timecreated, timemodified, userid) 
			SELECT " . $assignment . ", " . $curtime . ", " . $curtime . ", p.userid 
			FROM {forum} f
			     JOIN {forum_discussions} d ON d.forum = f.id
			     JOIN {forum_posts} p       ON p.discussion = d.id
			WHERE f.id = " . $forumid . "
			  AND NOT EXISTS(SELECT id FROM {assign_submission} WHERE userid = p.userid AND assignment = " . $assignment . ")
			GROUP BY p.userid";
	  
	  $DB->execute($sql);
	  	  
	  $sql = "
			INSERT INTO {assignsubmission_review}
			(assignment, submission, post_count, reply_count)
			SELECT " . $assignment . ", su.id, 
			        sum(CASE WHEN parent= 0 THEN 1 ELSE 0 end) AS post_count, 
			        sum(CASE WHEN parent> 0 THEN 1 ELSE 0 end) AS reply_count
			FROM {assign_submission} su, mdl_forum f
			     JOIN {forum_discussions} d ON d.forum = f.id
			     JOIN {forum_posts} p       ON p.discussion = d.id
			WHERE f.id = " . $forumid . "
			  AND su.assignment = " . $assignment . "
      			  AND su.userid = p.userid
			  AND NOT EXISTS(SELECT id FROM {assignsubmission_review} WHERE submission = su.id AND assignment = " . $assignment . ")
			GROUP BY p.userid";

	  $DB->execute($sql);

	  
	  /* now update existing submission with new post counts */
	  
	  /* update non-reply post counts */
	  $sql = "
			UPDATE  {assignsubmission_review}
			SET     post_count = (
			 SELECT sum(CASE WHEN parent = 0 THEN 1 ELSE 0 end) 
			   FROM {assign_submission} su, {forum} f 
			   JOIN {forum_discussions} d ON d.forum = f.id 
			   JOIN {forum_posts} p ON p.discussion = d.id 
			  WHERE f.id = " . $forumid . "
			    AND su.assignment = " . $assignment . "
			    AND su.userid = p.userid 
			    AND su.id = {assignsubmission_review}.submission
			  GROUP BY p.userid)";


	  $DB->execute($sql);

	  /* update reply counts */
	  $sql = "
			UPDATE {assignsubmission_review} 
			SET reply_count = (
			  SELECT sum(CASE WHEN parent > 0 THEN 1 ELSE 0 end) 
			  FROM {assign_submission} su, {forum} f 
			    JOIN {forum_discussions} d ON d.forum = f.id 
			    JOIN {forum_posts} p ON p.discussion = d.id 
			  WHERE f.id = " . $forumid . "
			    AND su.assignment = " . $assignment . "
			    AND su.userid = p.userid 
			    AND su.id = {assignsubmission_review}.submission
			  GROUP BY p.userid)";


	  $DB->execute($sql);


/* update modified times */
	  $sql = "
			UPDATE {assign_submission}
			SET timemodified = IfNull((
			  SELECT max(p.modified)  
			  FROM {forum} f 
			    JOIN {forum_discussions} d ON d.forum = f.id 
			    JOIN {forum_posts} p ON p.discussion = d.id 
			  WHERE f.id = " . $forumid . "
                            AND p.userid = {assign_submission}.userid
			  GROUP BY p.userid), 0)
			WHERE assignment = " . $assignment . 
	    " AND userid = {assign_submission}.userid";
	  

	    $DB->execute($sql);

  
	  /* set the submission status to submitted if we find posts, and change to
       * draft if the status is submitted but there are no longer any posts (student deleted
       * posts) - LHB 9/2/2014
       */	  
     $sql = "
 SELECT r.id as ar_id, s.id as submission_id, s.userid as userid, s.status,
          (if (r.post_count IS NULL, 0, r.post_count) + if (r.reply_count IS NULL, 0, r.reply_count)) as totalposts
      FROM {assignsubmission_review} r
          JOIN {assign_submission} s ON s.id = r.submission
      WHERE r.assignment = :assignment_id";
     
    if ($all_submissions = $DB->get_records_sql($sql, array('assignment_id' => $assignment))) {
	  /* change status to 'submitted' if there are posts recorded for the submission
	  so grader can see it, and to 'draft' if not (in case student deleted posts)
	   */
	   foreach ($all_submissions as $review_id => $subm) {
	      if (($subm->totalposts > 0) && ($subm->status != ASSIGN_SUBMISSION_STATUS_SUBMITTED)) {
	          $DB->execute("UPDATE {assign_submission} SET status = '" . ASSIGN_SUBMISSION_STATUS_SUBMITTED  ."'
                               WHERE id = " . $subm->submission_id);
          }else  if (($subm->totalposts == 0) && ($subm->status == ASSIGN_SUBMISSION_STATUS_SUBMITTED)) {
                $course_context = context_course::instance($COURSE->id);
                /* student can't call this, but it will be updated next time instructor
                 * looks at submissions */
                if (has_capability('mod/assign:grade', $course_context)) {
                    $this->assignment->revert_to_draft($subm->userid);
                }
          }
       }
    }
		
	}
      else if ($activity_type == ASSIGNSUBMISSION_REVIEW_TYPE_WIKI) 
	       print_error(get_string('badactivitytype', 'assignsubmission_review'));
      else{
	       print_error(get_string('badactivitytype', 'assignsubmission_review'));
      }
    }
    /*** end update_counts **/


    /**
     * Get the default setting for UI Activity Grader submission plugin
     *
     * @param MoodleQuickForm $mform The form to add elements to
     * @return void
     */
    public function get_settings(MoodleQuickForm $mform) {
      global $CFG, $COURSE, $DB, $PAGE;

      /* turn off ALLOW_WIKIS until implemented, but having
	 this allows some testing of code now */
      $ALLOW_WIKIS = 0;

      $typeoptions = array();

      $mform->disabledIf('assignsubmission_review_enabled', 'assignsubmission_review_disable', 'eq' , 1);
      
      // Get the forums for this course
      $forums = $DB->get_records('forum', array('course'=>$COURSE->id));
      $forumoptions = array();
      foreach($forums as $f) {
	$forumoptions[$f->id] = $f->name;
      }
      if (count($forumoptions) ==  0)
	{
	  $forums_enabled = 0;
	}
      else
	{
	  $forums_enabled = 1;
	  $typeoptions[ASSIGNSUBMISSION_REVIEW_TYPE_FORUM] = 'Forum';;
	}
      
      
      $wikis_enabled = 0;
      
      if ($ALLOW_WIKIS) 
	{
	  // Get the wikis for this course
	  $wikis = $DB->get_records('wiki', array('course'=>$COURSE->id));
	  $wikioptions = array();
	  foreach($wikis as $f) {
	    $wikioptions[$f->id] = $f->name;
	  }	if (count($wikioptions) == 0) {
	    $wikis_enabled = 0;
	  }
	  else
	    {
	      $wikis_enabled = 1;  
	      $typeoptions[ASSIGNSUBMISSION_REVIEW_TYPE_WIKI] = 'Wiki';
	    }
	}
      
      
      if (! $forums_enabled && ! $wikis_enabled) {
	$mform->addElement('html', '<span style="color: red">' . get_string('noactivities', 'assignsubmission_review') . '</span>');
	$mform->addElement('hidden', 'assignsubmission_review_disable', 1);
    $mform->setType('assignsubmission_review_disable', PARAM_BOOL);
	return(true);
      }
      
      $Atypedef = ASSIGNSUBMISSION_REVIEW_TYPE_FORUM;
      
      $mform->addElement('hidden', 'assignsubmission_review_disable', 0);
      
      /* don't worry about wikis not implemeted yet; this code won't be reached
	 without forums enabled */
      if (! $forums_enabled) {
	$Atypedef = ASSIGNSUBMISSION_REVIEW_TYPE_WIKI;
      }
      
      
      if ($this->assignment->has_instance()) 
	{
	  if ($review_instance = $this->get_config()) 
	    {
	      if (! isset($review_instance->enabled)) 
		{
		  $have_instance = 0;
		}
	      else
		{
		  $upgrade_time = '';
		  $is_upgrade = 0;
		  $have_instance = $review_instance->enabled;
		  if (isset($review_instance->upgraded))
		    {
		      $is_upgrade = $review_instance->upgraded;
		      if ($review_instance->upgraded > 1000000000) 
			{
			  /* probably is a date */
			  $upgrade_time = ' (' . userdate($review_instance->upgraded) . ') ';
			}
		    }
		}
	    }
	  else
	    {
	      $have_instance = 0;
	    }
	}else
	{
	  $have_instance = 0;
	}
      if ($have_instance) 
	{
	  $Atypedef = $review_instance->activitytype;
	}
      
      $PAGE->requires->js('/lib/jquery/jquery-1.4.2.min.js');
      
      $mform->addElement('html', '     
        <script>
                function reviewEnabledCheck(enabled) {
                        if (enabled == 1) {
         		     $(".review_enabled").show();
                        }else{
         		     $(".review_enabled").hide();
                        }
                }
        	function methodCheck(method) {
        		$(".methodoption").hide();
        		$(".method" + method).show();
        	}
        	function reviewActivityCheck(activitytype) {
                $(".activityoption").hide();
        		$(".review" + activitytype).show();
        	}
             document.body.addEventListener( "change", function( e ) {
                if ((e.target.id == "id_assignsubmission_review_enabled") &&
                    ( e.target.type =="checkbox" )) {
                    reviewEnabledCheck ((e.target.checked? 1:0) );
                }else{
                    return(true);
                } 
            }, true);
        </script>
');
	 

      $hide_block = ($have_instance && $review_instance->enabled)?'':'; display:none';
      $mform->addElement('html', '<div class="review_enabled" style="border-style: solid; border-width:1px; border-color: #da5013; background-color: #f7f6f1; padding: 5px; margin-top: 5' . $hide_block . '">');


      $mform->addElement('html', '<div style="text-align: center; font-weight: bold;">Activity Grader Settings</div>');
    
      $mform->addElement('static', 'assignsubmission_review_grading_help', 
            get_string('gradingtipslab', 'assignsubmission_review'), get_string('gradingtips', 'assignsubmission_review'));
       
       
      $mform->addHelpButton('assignsubmission_review_grading_help', 'gradingform', 'assignsubmission_review');

      $mform->addElement('select', 'assignsubmission_review_activitytype', get_string('activitytype', 'assignsubmission_review'), $typeoptions, 'onchange="reviewActivityCheck($(this).val());"');
      
      if (! $forums_enabled)  {
	$mform->addElement('html', '<span style="color: red">' . get_string('noforums', 'assignsubmission_review') . '</span>');
      }else if ((! $wikis_enabled) && $ALLOW_WIKIS) {
	$mform->addElement('html', '<span style="color: red">' . get_string('nowikis', 'assignsubmission_review') . '</span>');
      }
      
      $mform->setDefault('assignsubmission_review_activitytype', $Atypedef);
      
      $mform->addHelpButton('assignsubmission_review_activitytype', 'activitytype', 'assignsubmission_review');
      
      
      $mform->addElement('html', '<div class="reviewforum activityoption"  style="' .  
			 ($Atypedef==ASSIGNSUBMISSION_REVIEW_TYPE_FORUM?'':'display:none') . '">');
      
      
      /* when wikis or other types are added, need one of these for each */
      
      /* subjectid (e.g. forum id) */
      $mform->addElement('select', 'assignsubmission_review_forumid', get_string("forumsubject", "assignsubmission_review"), $forumoptions);
      $mform->setDefault('assignsubmission_review_forumid', ($have_instance?$review_instance->subjectid:0));
      $mform->addHelpButton('assignsubmission_review_forumid', 'forumsubject', 'assignsubmission_review');
      
      //        $mform->addRule('assignsubmission_review_forumid', get_string('required'), 'required', null, 'client');
      
      // when wikis, etc, are added, this is set differently
      /* method (how to grade) */
      $methodoptions = array(ASSIGNSUBMISSION_REVIEW_METHOD_POSTSREPLIES => get_string('postsandreplies', 'assignsubmission_review'),
			     ASSIGNSUBMISSION_REVIEW_METHOD_TOTALPOSTS => get_string('totalposts', 'assignsubmission_review'));
      $mform->addElement('select', 'assignsubmission_review_forum_method', get_string('reviewforumgradingmethod', 'assignsubmission_review'), $methodoptions, 'onchange="methodCheck($(this).val());"');
      /* add a help button for wiki too, when enabled */
      $mform->addHelpButton('assignsubmission_review_forum_method', 'reviewforumgradingmethod', 'assignsubmission_review');
      
      $mform->setDefault('assignsubmission_review_forum_method', ($have_instance?$review_instance->forum_method:ASSIGNSUBMISSION_REVIEW_METHOD_POSTSREPLIES));
      //        $mform->addRule('assignsubmission_review_forum_method', get_string('required'), 'required', null, 'client');
      
      $mform->addElement('html', '<div class="method1 method2 methodoption">');
      
      
      /* posts_min */
      $mform->addElement('text', 'assignsubmission_review_posts_min', get_string("posts_min", "assignsubmission_review"));
      $mform->setType('assignsubmission_review_posts_min', PARAM_INT);
      $mform->setDefault('assignsubmission_review_posts_min', ($have_instance?$review_instance->posts_min:1));
      $mform->addHelpButton('assignsubmission_review_posts_min', 'reviewminpost', 'assignsubmission_review');
      
      /* posts_duedate */	
      $mform->addElement('date_time_selector', 'assignsubmission_review_posts_duedate', get_string('posts_duedate', 'assignsubmission_review'), array('optional'=>true));
      $mform->setDefault('assignsubmission_review_posts_duedate', ($have_instance?$review_instance->posts_duedate : time()+7*24*3600));	
      $mform->addElement('html', '</div>');  // close method1/method2 div
      
      /* reply_gradevalue */
      $mform->addElement('html', '<div class="method2 methodoption" style="' .  ($have_instance && $review_instance->forum_method==1?'display:none':'').'";>');
      
      /* reply_min */
      $mform->addElement('text', 'assignsubmission_review_reply_min', get_string("reply_min", "assignsubmission_review"));
      $mform->setType('assignsubmission_review_reply_min', PARAM_INT);
      $mform->setDefault('assignsubmission_review_reply_min', ($have_instance?$review_instance->reply_min:1));
      $mform->addHelpButton('assignsubmission_review_reply_min', 'reviewminreply', 'assignsubmission_review');
      
      /* reply_duedate */	
      $mform->addElement('date_time_selector', 'assignsubmission_review_reply_duedate', get_string('reply_duedate', 'assignsubmission_review'), array('optional'=>true));
      $mform->setDefault('assignsubmission_review_reply_duedate', ($have_instance?$review_instance->reply_duedate : time()+7*24*3600));
      $mform->addElement('html', '</div>' . "\n<!-- close methoption div -->\n"); // close methodoption div
      $mform->addElement('html', '</div>' . "\n<!-- close forum settings div -->\n"); // close div for forum  settings
      
      
      if ($ALLOW_WIKIS) 
	{
	  $mform->addElement('html', '<div class="reviewwiki activityoption"  style="' .
			     ($Atypedef==ASSIGNSUBMISSION_REVIEW_TYPE_WIKI?'':'display:none') . '">');
	  
	  /* subjectid (e.g. forum id) */
	  $mform->addElement('select', 'assignsubmission_review_wikiid', get_string("wikisubject", "assignsubmission_review"), $wikioptions);
	  $mform->setDefault('assignsubmission_review_wikiid', ($have_instance?$review_instance->subjectid:0));
	  
	  $mform->addElement('html', '</div>' . "\n<!-- close wiki settings div -->\n"); // close div for wiki settings
	}

    if ($have_instance) 
    {
      if ($is_upgrade) 
	{
	  /* has to be a forum, because no other activity existed in  2.2 assignments */

	  $old_grades = '<tr><td style="width: 25%; text-align: right; white-space:nowrap">' . 
	    get_string("oldpostval", "assignsubmission_review") .
	    $review_instance->posts_gradevalue;

	  if ($review_instance->forum_method == ASSIGNSUBMISSION_REVIEW_METHOD_POSTSREPLIES) {
	    $old_grades .= '<td>' . get_string("oldreplyval", "assignsubmission_review") .
	      $review_instance->reply_gradevalue . '</td>';
	  }
	  else
	    {
	      $old_grades .= '<td></td>';
	    }
	  $old_grades .= '</tr></table>';

	  $mform->addElement('html', '<table style="margin-top: 10"><tr><td colspan=2 style="font-weight: bold; padding: 0">' . 
			     get_string("wasupgraded", "assignsubmission_review") . 
			     $upgrade_time . '</td></tr>' . $old_grades);
	}
     }                  
      $mform->addElement('html', '</div>' . "\n<!--close all review settings div -->\n"); // close div for all review settings 
      
      /*** disable forum settings of wiki is on **/	
      $mform->disabledIf('assignsubmission_review_forumid', 'assignsubmission_review_activitytype', 'ne', 'forum');
      $mform->disabledIf('assignsubmission_review_forum_method', 'assignsubmission_review_activitytype', 'ne', 'forum');
      $mform->disabledIf('assignsubmission_review_posts_min', 'assignsubmission_review_activitytype', 'ne', 'forum');
      $mform->disabledIf('assignsubmission_review_reply_min', 'assignsubmission_review_activitytype', 'ne', 'forum');
      $mform->disabledIf('assignsubmission_review_posts_duedate', 'assignsubmission_review_activitytype', 'ne', 'forum');
      $mform->disabledIf('assignsubmission_review_reply_duedate', 'assignsubmission_review_activitytype', 'ne', 'forum');
      
      /** disable wiki settings if forum is on (more needed)*/
      $mform->disabledIf('assignsubmission_review_wikiid', 'assignsubmission_review_activitytype', 'ne', 'wiki');
      /* add more above */
      
      $mform->disabledIf('assignsubmission_review_activitytype', 'assignsubmission_review_enabled', 'eq', 0);
      $mform->disabledIf('assignsubmission_review_forumid', 'assignsubmission_review_enabled', 'eq', 0);
      $mform->disabledIf('assignsubmission_review_forum_method', 'assignsubmission_review_enabled', 'eq', 0);
      $mform->disabledIf('assignsubmission_review_posts_min', 'assignsubmission_review_enabled', 'eq', 0);
      $mform->disabledIf('assignsubmission_review_posts_duedate', 'assignsubmission_review_enabled', 'eq', 0);

      $mform->disabledIf('assignsubmission_review_reply_min', 'assignsubmission_review_enabled', 'eq', 0);
      $mform->disabledIf('assignsubmission_review_reply_duedate', 'assignsubmission_review_enabled', 'eq', 0);
      $mform->disabledIf('assignsubmission_review_reply_min', 'assignsubmission_review_forum_method', 'eq', 1);
      $mform->disabledIf('assignsubmission_review_reply_duedate', 'assignsubmission_review_forum_method', 'eq', 1);
      
    }

    /**
     * Save the settings for review submission plugin
     *
     * @param stdClass $data
     * @return bool
     */
    public function save_settings(stdClass $data) {

      /* if we got here with no activity type, probablys screwed up
	 the form.  Disable activity grader */
      if (! $data->assignsubmission_review_activitytype) 
	{
	  $this->set_config('enabled', 0);
	  return(true);
	}

      /* assignment is created just before calling this, so we do have an
	 instance if we need to get in there */
      //	$assignment = $this->assignment->get_instance();

      
      /* is this a new assignment or are we just updating? */
      if ($this->get_config('activitytype')) 
	{
	  /* it's not new if it already as an activity type */
	  $this->set_config('modified', time());
	}
      
      $this->set_config('activitytype', $data->assignsubmission_review_activitytype);
      
      if ($data->assignsubmission_review_activitytype == ASSIGNSUBMISSION_REVIEW_TYPE_FORUM)
	{
	  $this->set_config('subjectid', $data->assignsubmission_review_forumid);  /* might be reused for wiki */
	  $this->set_config('forum_method', $data->assignsubmission_review_forum_method);
	  $this->set_config('posts_min', $data->assignsubmission_review_posts_min);
	  $this->set_config('posts_duedate', $data->assignsubmission_review_posts_duedate);
	  //	  $this->set_config('posts_gradevalue', $data->assignsubmission_review_posts_gradevalue);
	  //	  $this->set_config('reply_gradevalue', $data->assignsubmission_review_reply_gradevalue);
	  $this->set_config('reply_min', $data->assignsubmission_review_reply_min);
	  $this->set_config('reply_duedate', $data->assignsubmission_review_reply_duedate);
	}
      /*      else if ($data->assignment_review_activitytype == 'wiki') 
	{
	$this->set_config('subjectid', $data->assignsubmission_review_wikiid);
	} */
     

      return true;

    }


  /**
    * Get review submission information from the database
    *
    * @param  int $submissionid
    * @return mixed
    */
    private function get_review_submission($submissionid) {
        global $DB;

        return $DB->get_record('assignsubmission_review', array('submission'=>$submissionid));
    }

    /*  get forum post counts and time of most recent post
       for all users in the forum,
       associated with the current assignment 

       return: array(userid => array(userid=>userid, 
                                     post_count=n, 
                                     reply_count=n,
				     timemodified=utime),
                     userid => array(userid=>userid, 
                                     post_count=n, 
                                     reply_count=n,
				     timemodified=utime), ...)


    if userid is included (and non-zero), returns only the record
    for that user.

    returns null if user has no post data; empty array if nobody does.
*/
    private function get_forum_post_counts($userid=0) 
    {
      global $DB;


      if (! $forumid = $this->get_config('subjectid')) 
	{
	   print_error(get_string('noforumid', 'assignsubmission_review'));
	}
      if ($userid > 0) 
	{
	  $userparam = 'AND p.userid = ' . $userid;
	}
      else
	{
	  $userparam = '';
	}
      
      $SQL = "       
         SELECT p.userid AS userid,
	        sum(CASE WHEN parent= 0 THEN 1 ELSE 0 end) AS post_count, 
	        sum(CASE WHEN parent> 0 THEN 1 ELSE 0 end) AS reply_count,
                max(p.modified) AS timemodified
         FROM {forum} f
         JOIN {forum_discussions} d ON d.forum = f.id
         JOIN {forum_posts} p ON p.discussion = d.id
         WHERE f.id = :forumid $userparam
                  GROUP BY p.userid";

      $params = array('forumid' => $forumid);
      if ($result = $DB->get_records_sql($SQL, $params)) 
	{
	  if ($userid > 0) {
	    if (isset($result[$userid])) 
	      {
		return($result[$userid]);
	      }
	  }
	  else
	    {
	      return($result);
	    }
	}
      if ($userid) 
	{
	  return(null);
	}
      else
	{
	  return(array());
	}
    }
    

    /**
     * Add form elements for settings
     *
     * @param mixed $submission can be null
     * @param MoodleQuickForm $mform
     * @param stdClass $data
     * @return true if elements were added to the form
     *
     (
     */
    public function get_form_elements($submission, MoodleQuickForm $mform, stdClass $data) {

      global $CFG, $USER, $DB;

      
      if (! $activity_info = $this->get_config()) 
	{
	  print_error('cannot get assignment information');
	} 
      
      if (! $subject_info = $this->get_subject_info($activity_info)) 
	{
	   print_error(get_string('cannotgettype', 'assignsubmission_review') . ' ' . $submission->id);
	}
      
      $activity_type = $activity_info->activitytype;
      $activity_name = $subject_info->name;
      $activity_intro = $subject_info->intro;

      $assignment = $this->assignment->get_instance()->id;
      $userid = $USER->id;


      /* establish post counts already known */
      $postcnt = 0;
      $replycnt = 0;
      if ($submission) 
	{
	  $review_submission = $this->get_review_submission($submission->id);
	  if ($review_submission) 
	    {
	      if ($activity_type == ASSIGNSUBMISSION_REVIEW_TYPE_FORUM) 
		{
		  $postcnt = (! empty($review_submission->post_count))?$review_submission->post_count:0;
		  $replycnt = (! empty($review_submission->reply_count))?$review_submission->reply_count:0;
		}  // else if ($activity_type == ASSIGNSUBMISSION_REVIEW_TYPE_WIKI) 
	    }
	}

      /* update to find new posts */
      $this->update_counts();

      /* check updated submissions info */
      if ($submission) 
	{
	  $submission_id = $submission->id;
	}
      else
	{
	  $new_submission = $DB->get_record('assign_submission', array('userid' => $userid, 'assignment' => $assignment));
	  if ($new_submission) 
	    {
	      $submission_id = $new_submission->id;
	    }else
	    {
	      $submission_id = 0;
	    }
	}
      if ($submission_id) 
	{
	  if ($review_submission = $this->get_review_submission($submission_id)) 
	    {
	      if ($activity_type == ASSIGNSUBMISSION_REVIEW_TYPE_FORUM) 
		{
		  $new_postcnt = (! empty($review_submission->post_count))?$review_submission->post_count:0;
		  $new_replycnt = (! empty($review_submission->reply_count))?$review_submission->reply_count:0;
		} /*
		    else if ($activity_type == ASSIGNSUBMISSION_REVIEW_TYPE_WIKI) {
		    equivalent of above, for wiki;
		    }
		  */
	    }else
	    {
	      $new_postcnt = $new_replycnt = 0;
	    }
	}
      else

	{
	  $new_postcnt = $new_replycnt = 0;
	}
      
      
      $mform->addElement('html', '<div>');

      if ($activity_type == ASSIGNSUBMISSION_REVIEW_TYPE_FORUM) 
	{
	  $mform->addElement('html', get_string('activitytype', 'assignsubmission_review') . ': <span style="font-weight: bold">' . get_string('forumhd', 'assignsubmission_review') . '</span><br />' .
			     get_string('forumname', 'assignsubmission_review') . 
			     ': <span style="font-weight: bold">' . $activity_name . '</span><br />');
	  if (($new_postcnt + $new_replycnt) == 0) {
	    $mform->addElement('html', get_string('noposts', 'assignsubmission_review'));
	  }else{
	    if ($activity_info->forum_method == ASSIGNSUBMISSION_REVIEW_METHOD_TOTALPOSTS) 
	      {
		$mform->addElement('html', get_string('post_count', 'assignsubmission_review') .
				   ': ' . ($new_postcnt + $new_replycnt) . ' (' . get_string('minlab', 'assignsubmission_review') . ' ' . 
				   $activity_info->posts_min . ')<br />');
		
	      }
	    else
	      {
		$mform->addElement('html', get_string('post_count', 'assignsubmission_review') .
                                        ': ' . $new_postcnt . ' (' .
 			   get_string('minrequired', 'assignsubmission_review') .
             				   $activity_info->posts_min . ')<br />' .
				   get_string('reply_count', 'assignsubmission_review') . 
				   ': ' . $new_replycnt . ' (' . 
                                    get_string('minrequired', 'assignsubmission_review') .
            				   $activity_info->reply_min . ')<br />');
	      }

	  }
	  $mform->addElement('html', '<div style="font-style: italic"><br />' .
			     get_string('forumsubmission', 'assignsubmission_review') . '</div>');
	  
	  $link = $CFG->wwwroot.'/mod/forum/view.php?f=' . $activity_info->subjectid;
	  $mform->addElement('html', '<div style="text-align: center"><a href="' . $link . '">Go To Forum</a></div>');
	}	// else if ($activity_type == ASSIGNSUBMISSION_REVIEW_TYPE_WIKI) 


/* else for wiki, etc */
	      

        return true;
    }

     /**
      * Save submission data to the database.  Only used of student clicks
      * "save submission" button, which isn't necessary with activity grader
      * unless submission comments are enabled.
      *
      * @param stdClass $submission
      * @param stdClass $data
      * @return bool
      */

     public function save(stdClass $submission, stdClass $data) {
        global $USER, $DB;

	$assignmentid = $this->assignment->get_instance()->id;
	$review_instance = $this->get_config();
	$userid = $USER->id;
	$activitytype = $review_instance->activitytype;
	
	if ($activitytype == ASSIGNSUBMISSION_REVIEW_TYPE_FORUM) 
	  {
	    $forumid = $review_instance->subjectid;

	/* get counts post counts */

	    if ($Counts = $this->get_forum_post_counts($userid)) 
	      {
		$postcount = $Counts->post_count;
		$replycount = $Counts->reply_count;
	      }
	    else
	      {
		$postcount = 0;
		$replycount = 0;
		$modified = 0;
	      }
	    $reviewsubmission = $this->get_review_submission($submission->id);
	    if (! $reviewsubmission) 
	      {
		$reviewsubmission = new stdClass();
		$reviewsubmission->assignment = $assignmentid;
		$reviewsubmission->submission = $submission->id;
		$reviewsubmission->post_count = $postcount;
		$reviewsubmission->reply_count = $replycount;
		return $DB->insert_record('assignsubmission_review', $reviewsubmission);
	      }else
	      {
		$reviewsubmission->post_count = $postcount;
		$reviewsubmission->reply_count = $replycount;
		return $DB->update_record('assignsubmission_review', $reviewsubmission);
	      }
	  }
	else if ($activitytype == ASSIGNSUBMISSION_REVIEW_TYPE_WIKI) 
	  {
	    print_error(get_string('badactivitytype', 'assignsubmission_review'));
	  }
	else
	  {
	    print_error(get_string('badactivitytype', 'assignsubmission_review'));
	  }
     }
     

     /**
      * 
      *
      * @param stdClass $submission
      * @param bool $showviewlink - If the summary has been truncated set this to true
      * @return string
      *
      */
    public function view_summary(stdClass $submission, & $showviewlink) {
        global $CFG;

	$userid = $submission->userid;
	
	$activitytype = $this->get_config('activitytype');

	$this->update_counts();
	$reviewsubmission = $this->get_review_submission($submission->id);

	// always show the view link
	$showviewlink = true;

	$summary = '';

	// check html arg to see if we're on the grading page
        $action_in = optional_param('action', '', PARAM_STRINGID);
	$savegrade = optional_param('savegrade', '', PARAM_RAW);
	$cancel = optional_param('cancelbutton', '', PARAM_RAW);

	/* grading page is already cluttered enough; try to figure
	   out if we are there and subpress the extra help
	   message */
	$no_help = ($action_in == 'grading') || $cancel || $savegrade;

	  if ($showviewlink && ! $no_help)
	  {
	    $summary .= '<span style="display: inline; font-style: italic; font-size: 90%">' . get_string('clicktoviewfull', 'assignsubmission_review') . '</span>';
	  }
	
	if ($activitytype == ASSIGNSUBMISSION_REVIEW_TYPE_FORUM) 
	  {
	    $forum_method = $this->get_config('forum_method');
	    if ($reviewsubmission) 
	      {
		$posts = ($reviewsubmission->post_count)?$reviewsubmission->post_count:0;
		$replies = ($reviewsubmission->reply_count)?$reviewsubmission->reply_count:0;
	      }
	    else
	      {
		$posts = 0;
		$replies = 0;
	      }
	    if ($forum_method == ASSIGNSUBMISSION_REVIEW_METHOD_TOTALPOSTS) 
	      {
		$posts += $replies;
	      }
	    $summary .= '<div style="padding: 0; white-space: nowrap">' . 
	      get_string('posts', 'assignsubmission_review') . ': ' .  $posts;
	    
	    if ($forum_method != ASSIGNSUBMISSION_REVIEW_METHOD_TOTALPOSTS) 
	      {
		$summary .= '</div><div  style="padding: 0; white-space: nowrap">';
		$summary .=  get_string('replies', 'assignsubmission_review') . ': ' .  $replies;
	      }
	    $summary .= '</div>';

	/*}	else if ($activitytype == 'wiki') 
	  {
	    if ($reviewsubmission) 
	      {
		$wiki_whatever1 = ...
		$wiki_whatever1 = ...
	      }
	    else
	      {
		$wiki_whatever1 = 0;
		$wiki_whatever1 = 0;
	      }
	    $summary .= "wikiduh: ...  ";
	*/	    
	  }
	
	return $summary;
    }

    /* from old code */
    function show_generic_row($message, $user = null) {
      global $COURSE;

      $outcode = '';
      
      $outcode .= '<tr>';
      if ( !is_null($user) ) {
	$outcode .= '<td class="picture user">';
	print_user_picture($user, $COURSE->id, $user->picture);
	$outcode .=  '</td><td class="content">';
      } else {
	$outcode .= '<td class="content" colspan="2">';
      }
      $outcode .= $message . '</td></tr>';

      return($outcode);
      
    }


    function show_post_row($post, $user, $due_date) {
      global $CFG, $OUTPUT, $COURSE;
    	
	$options = new stdClass();
	$options->para      = false;
	$options->trusttext = true;
    	
	$outcode = '';
	
        $outcode .= '<tr><td class="picture user">';
	$outcode .= $OUTPUT->user_picture($user, array('courseid'=>$COURSE->id)) . '</td>';
	$outcode .=  '<td class="content">' . 
	  '<div class="PostTitle">' . $post->subject . '</div>' .
	  '<div class="time">' . $post->firstname . " " . $post->lastname . ' - ' . 
	  date('l, n/j/Y g:i a', $post->created);

	if ($due_date < $post->created) {
	  //	  $outcode .=  ' - <strong>Past Due Date</strong>';
	  $outcode .=  ' - <span style="color: red; font-weight: bold">Past Due Date</span>';
	}
	$outcode .=  '</div>';
	
	if ((strlen(strip_tags($post->message)) > $CFG->forum_longpost)) {
	  // Print shortened version
	  $outcode .= '<div id="Post-' . $post->id . '">';
	  $outcode .= format_text(forum_shorten_post($post->message), $post->format, $options);
	  $numwords = count_words(strip_tags($post->message));
	  $outcode .= '<div class="posting"><a href="#" onclick="$(\'#Post-' . $post->id . '\').hide(); $(\'#Post2-' . $post->id . '\').show(); return false;" >';
	  $outcode .= get_string('readtherest', 'forum') .
	    '</a> ('.get_string('numwords', '', $numwords).')...</div>';
	  $outcode .= '</div><div id="Post2-' . $post->id . '" style="display:none;">' .
	    format_text($post->message, $post->format, $options) .
	    '</div>';
	} else {
	  // Print whole message
	  $outcode .= '<div class="posting">' .
	    format_text($post->message, FORMAT_MOODLE, $options) .
	    '</div>';
	}
	
	$outcode .= '<div class="Commands">';
	// Link to Parent
	if ( $post->parent ) {
	  $link = $CFG->wwwroot.'/mod/forum/discuss.php?d='.$post->discussion.'&amp;parent='.$post->parent;
	  $button = $OUTPUT->action_link($link, get_string('parent', 'forum'), 
					 new popup_action('click', $link, 'forums', 
							  array('height' => 600, 'width' => 750)));
	  $outcode .= $button . ' | ';
	}
	
	// Show post in Context
	//	$link = $CFG->wwwroot.'/mod/forum/discuss.php?d='.$post->discussion.'&amp;parent='.$post->id;
	$link = $CFG->wwwroot.'/mod/forum/discuss.php?d='.$post->discussion;
	$outcode .= $OUTPUT->action_link($link, get_string('discussion', 'forum'),
					 new popup_action('click', $link, 'forums', array('height' => 600, 'width' => 750)));


	$outcode .= '</td>';
        $outcode .= '</tr>';

	return($outcode);
    }

    /**
     * 
     *
     * @param stdClass $submission
     * @param stdClass $review_instance
     * @return string
     * 
     * display the posts, replies, etc.  most of this code pulled from 
     * old 2.2 types/review plugin
     */

    private function view_forum_submission(stdClass $submission, stdClass $review_instance) 
    {
      global $CFG, $DB, $COURSE, $OUTPUT, $PAGE;
      
      require_once($CFG->dirroot.'/mod/forum/lib.php');
      
      $result = '';
      $userid = $submission->userid;


      $this->update_counts();
      
      $reviewsubmission = $this->get_review_submission($submission->id);
      
      $method = $review_instance->forum_method;
      $subjectid = $review_instance->subjectid;

      $posts_min = ($review_instance->posts_min > 0)?$review_instance->posts_min:0;

      if ($review_instance->forum_method == ASSIGNSUBMISSION_REVIEW_METHOD_POSTSREPLIES) 
	{
	  $replies_min = ($review_instance->reply_min > 0)?$review_instance->reply_min:0;
	}else
	{
	  $replies_min = 0;
	}
      
      if (!$user = $DB->get_record('user', array('id'=>$userid))) {
	print_error('No such user!');
      }
      $PAGE->requires->js('/lib/jquery/jquery-1.4.2.min.js');      
      $result .= "\n<!-- Begin forum submission view -->\n" .
	'<style>' .
	'	.PostTitle{font-weight:bold;}' .
	'	.Pass{background-color:#336633; padding:0px 5px; color:white;}' .
	'	.Fail{background-color:#993333; padding:0px 5px; color:white;}' .
	'</style>';
      
      $result .=  '<table cellspacing="0" class="feedback" >';

      $result .= '<tr>' .
	'<td class="picture user">' .
	$OUTPUT->user_picture($user, array('courseid'=>$COURSE->id)) .
	'</td>' .
	'<td class="">' .
	'<div class="from">' .
	'<div class="fullname">'.fullname($user, true).'</div>';
      
      if ($submission->timemodified) {
	$result .= '<div class="time">' . userdate($submission->timemodified) . '</div>';
      }
      $result .= '</div>
		</td></tr>';
      /* call to print_user_files was above before /td tag */
      ///End of student info row
      $posts = forum_get_user_posts($subjectid, $userid);
      $PostsPastDue = 0;
      $RepliesPastDue = 0;

      /* the number used for duedate when there isn't one needs to be 
	 updated after 11/20/2286
      */	  
      $pduedate = ($review_instance->posts_duedate > 0)?$review_instance->posts_duedate:9999999999;
      $rduedate = ($review_instance->reply_duedate > 0)?$review_instance->reply_duedate:9999999999;

      if ($posts) {
	if ($method == ASSIGNSUBMISSION_REVIEW_METHOD_TOTALPOSTS) {
	  $TotalPosts = count($posts);
	  $TotalReplies = 0;
	  foreach($posts as $post) {
	    $result .= $this->show_post_row($post, $user, $pduedate);
	    if ($post->created > $pduedate) 
	      {
		$PostsPastDue++;
	      }
	  }
	} else {
	  /* posts and replies method */
	  $TotalPosts = 0;
	  $TotalReplies = 0;

	  $replies = array();
	  foreach($posts as $key=>$post) {
	    if ($post->parent > 0) {
	      $TotalReplies++;
	      if ($post->created > $rduedate)  {
		$RepliesPastDue++;
	      }
	      $replies[] = $post;
	      unset($posts[$key]);
	    }else{
	      $TotalPosts++;
	    }
	  }
	  if ($posts) {
	    $result .= $this->show_generic_row('<strong>' . get_string('posts', 'assignsubmission_review') . '</strong>');
	    foreach($posts as $post) {
	    if ($post->created > $pduedate) 
	      {
		$PostsPastDue++;
	      }
	      $result .= $this->show_post_row($post, $user, $pduedate);
	    }
	  }
	  if ( $replies) {
	    $result .= $this->show_generic_row('<strong>' . get_string('replies', 'assignsubmission_review') . '</strong>');
	    foreach( $replies as $reply) {
	      $result .= $this->show_post_row($reply, $user, $rduedate);
	    }
	  }
	  
	}

    $totpo = new stdClass();
    $totpo->totpost = $TotalPosts;
    $totpo->pmin = $posts_min;
    $totpo->totrep = $TotalReplies;
    $totpo->rmin = $replies_min;
    $totpo->poverdue = $PostsPastDue;
    $totpo->roverdue = $RepliesPastDue;
 
   
	if (($TotalPosts < $posts_min) ||
	    ($TotalReplies < $replies_min)) 
	  {
	    $result .= '<tr><td colspan="2"><ul style="list-style-type: none">';
                     
	    if ($TotalPosts < $posts_min) 
	      {
		   $result .= '<li style="color: red">' . get_string('toofewposts', 'assignsubmission_review', $totpo)  . '</li>';       
        
	      }
	    
	    if ($TotalReplies < $replies_min) 
	      {
	   	     $result .= '<li style="color: red">' . get_string('toofewreplies', 'assignsubmission_review', $totpo)  . '</li>'; 
                
	      }
	    $result .= "</ul></td></tr>";
	  }
	if (($PostsPastDue > 0) ||
	    ($RepliesPastDue > 0))
	  {
	    $result .= '<tr><td colspan="2"><ul style="list-style-type: none">';
	    if ($PostsPastDue > 0) 
	      {
		$result .= '<li style="color: red">' . get_string('postsoverdue', 'assignsubmission_review', $totpo)  . '</li>';       
	      }
	    if ($RepliesPastDue > 0) 
	      {
		$result .= '<li style="color: red">' . get_string('repliesoverdue', 'assignsubmission_review', $totpo)  . '</li>';  
	      }
	    $result .= '</ul></td></tr>';
	  }
	
      }

      if (! empty($review_instance->upgraded)) 
	{
	  if ($reviewsubmission->post_grade || $reviewsubmission->reply_grade) 
	    {
	      $course_context = context_course::instance($COURSE->id);
	      if (has_capability('gradereport/grader:view', $course_context)) 
		{
		   $vwgrade = new stdClass();          
		   $vwgrade->pgrade = ($reviewsubmission->post_grade)?$reviewsubmission->post_grade:'0';
		   $vwgrade->rgrade = ($reviewsubmission->reply_grade)?$reviewsubmission->reply_grade:'0';
          
           $result .= '<tr><td colspan="2" style="font-size: 80%">' . 
                get_string('upgradednote', 'assignsubmission_review', $vwgrade) .  
		      "</td></tr>\n";
		}
	    }
	}
      
      $result .= "</table>\n<!-- END forum submission view -->\n";


      return $result;
    }
    

    /**
     * 
     *
     * @param stdClass $submission
     * @param stdClass $review_instance
     * @return string
     * 
     *
     *
     */

    private function view_wiki_submission(stdClass $submission, stdClass $review_submission) 
    {
      global $CFG, $DB, $COURSE, $OUTPUT;

        print_error(get_string('unimpsubmission', 'assignsubmission_review'));
    }


    /**
     * 
     *
     * @param stdClass $submission
     * @return string
     * 
     * display the posts, replies, etc.  most of this code pulled from 
     * old 2.2 types/review plugin
     */
    public function view(stdClass $submission) {      


      $review_instance = $this->get_config();
      if ($review_instance->activitytype == ASSIGNSUBMISSION_REVIEW_TYPE_FORUM)  
	{
	  return $this->view_forum_submission($submission, $review_instance);
	}else if ($review_instance->activitytype == ASSIGNSUBMISSION_REVIEW_TYPE_WIKI) 
	{
	  return $this->view_wiki_submission($submission, $review_instance);
	}
      else
	{
	  print_error(get_string('badactivitytype', 'assignsubmission_review'));
	}
    }
   
    

     /**
     * Return true if this plugin can upgrade an old Moodle 2.2 assignment of this type and version.
     *
     * @param string $type old assignment subtype
     * @param int $version old assignment version
     * @return bool True if upgrade is possible
     */
    public function can_upgrade($type, $version) {
        if ($type == 'review' && $version >= 2012022100) {
            return true;
        }
        return false;
    }

    /**
     * Upgrade the submission from the old assignment to the new one
     *
     * @param context $oldcontext - the database for the old assignment context
     * @param stdClass $oldassignment The data record for the old assignment
     * @param stdClass $oldsubmission The data record for the old submission
     * @param stdClass $submission The data record for the new submission
     * @param string $log Record upgrade messages in the log
     * @return bool true or false - false will trigger a rollback
     */
    public function upgrade(context $oldcontext, stdClass $oldassignment, stdClass $oldsubmission, stdClass $submission, & $log) {
        global $DB;

	/* check for a review_submission_record */

	$old_review = $DB->get_record('assignment_review_submission',  array('submissionid' => $oldsubmission->id));
	
	if ($old_review) 
	  {
	    $reviewsubmission = new stdClass();
	    $reviewsubmission->assignment = $submission->assignment;
	    $reviewsubmission->submission = $submission->id;
	    $reviewsubmission->post_count = $old_review->post_count;  
	    $reviewsubmission->reply_count = $old_review->reply_count;
	    $reviewsubmission->post_grade = $old_review->post_grade;
	    $reviewsubmission->reply_grade = $old_review->reply_grade;
	    
	    $NewID = $DB->insert_record('assignsubmission_review', $reviewsubmission);
	    if ($NewID <= 0) 
	      {
		$log .= get_string('couldnotconvertsubmission', 'mod_assign', $submission->userid);
		return false;
	      }

	  }

        return true;
    }

    /**
     * Upgrade the settings from the old assignment to the new plugin based one
     *
     * @param context $oldcontext - the database for the old assignment context
     * @param stdClass $oldassignment - the database for the old assignment instance
     * @param string $log record log events here
     * @return bool Was it a success?
     */
    public function upgrade_settings(context $oldcontext, stdClass $oldassignment, & $log) {
      global $DB;

        // first upgrade settings (nothing to do)

      $conf_settings = new stdClass();
      
      if (! $old_instance = $DB->get_record('assignment_review_instance', array('assignmentid'=>$oldassignment->id))) 
	{
	  $log .= get_string('conversionexception', 'mod_assign', 'Could not find assignment_review_instance record');
	  return false;
	}


      /* ALL the old types were 'forum' types, so don't need to convert */      
      $this->set_config('activitytype', ASSIGNSUBMISSION_REVIEW_TYPE_FORUM);
      $this->set_config('subjectid', $old_instance->subjectid);  /* might be reused for wiki */
      $this->set_config('forum_method', $old_instance->method);
      $this->set_config('posts_gradevalue', $old_instance->posts_gradevalue);
      $this->set_config('posts_min', $old_instance->posts_min);
      $this->set_config('posts_duedate', $old_instance->posts_duedate);
      $this->set_config('reply_gradevalue', $old_instance->reply_gradevalue);
      $this->set_config('reply_min', $old_instance->reply_min);
      $this->set_config('reply_duedate', $old_instance->reply_duedate);
      /* if this assignment was an upgraded one from 2.2, should be time of
	 upgrade or at least a postivie int, else false or 0 */
      $this->set_config('upgraded', time());
      $this->set_config('check_method', 1); /* signal that we need to
					       make sure grading method is guide */

      /* check grade setting; in the old types the setting in the assignment record
       wasn't used, so must check the review_instance record for settings */
      if ($old_instance->method == ASSIGNSUBMISSION_REVIEW_METHOD_POSTSREPLIES) 
	{
	  $maxgrade = $old_instance->posts_gradevalue + $old_instance->reply_gradevalue;
	}
      else
	{
	  $maxgrade = $old_instance->posts_gradevalue;
	}
      if ($maxgrade != $this->assignment->get_instance()->grade)
	{
	  $newdata = new stdClass();
	  $newdata->id = $this->assignment->get_instance()->id;
	  $newdata->grade = $maxgrade;
	  $DB->update_record('assign', $newdata);

	}
      
      return true;
    }

    
    /**
     * The assignment has been deleted - cleanup
     *
     * @return bool
     */
    public function delete_instance() {
        global $DB;
        // will throw exception on failure
        $DB->delete_records('assignsubmission_review', array('assignment'=>$this->assignment->get_instance()->id));

        return true;
    }

    /**
     * Formatting for log info
     *
     * @param stdClass $submission The new submission
     * @return string
     *
     * This may never get called for review types
     */
    public function format_for_log(stdClass $submission) {
        // format the info for each submission plugin add_to_log
        $reviewsubmission = $this->get_review_submission($submission->id);
        $reviewloginfo = get_string('posts', 'assignsubmission_review') . ': ' .  $reviewsubmission->post_count . ' ' . 
                           get_string('replies', 'assignsubmission_review') . ': ' . $reviewsubmission->reply_count;
        return $reviewloginfo;
    }


    /**
     * No text is set for this plugin
     *
     * @param stdClass $submission
     * @return bool
     * 
     */
    public function is_empty(stdClass $submission) {

      $reviewsubmission = $this->get_review_submission($submission->id);
      
      if (empty($reviewsubmission)) 
	{
	  return(true);
	}
      $activity_type = $this->get_config('activitytype');
      
      if ($activity_type == ASSIGNSUBMISSION_REVIEW_TYPE_FORUM) 
	{
	  if (($reviewsubmission->post_count > 0) || ($reviewsubmission->reply_count > 0))
	    {
	      return(false);
	    }
	  //	}else if ($activity_type == ASSIGNSUBMISSION_REVIEW_TYPE_WIKI) 
	}
      return(true);
    }

}

