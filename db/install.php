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
 * Post-install code for the submission_onlinetext module.
 *
 * @package assignsubmission_review
* @copyright 2014 Larry Broda<lbroda@illinois.edu>/Nate Baxley<nbaxley@illinois.edu>/University of Illinois at Urbana Champaign {@link http://illinois.edu}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();


/**
 * Code run after the assignsubmission_review module database tables have been created.
 * 
 * @return bool
 */
function xmldb_assignsubmission_review_install() {
    global $CFG;

    // do the install

    require_once($CFG->dirroot . '/mod/assign/adminlib.php');
    // set the correct initial order for the plugins
    $pluginmanager = new assign_plugin_manager('assignsubmission');
    $pluginmanager->move_plugin('review', 'up');
    
    // do the upgrades
    return true;

}


