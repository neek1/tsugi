<?php
// A library for webscraping graders
require_once $CFG->dirroot."/pdo.php";
require_once $CFG->dirroot."/lib/lms_lib.php";

require_once $CFG->dirroot."/lib/goutte/vendor/autoload.php";
require_once $CFG->dirroot."/lib/goutte/Goutte/Client.php";

// Check to see if we were launched from LTI, and if so set the 
// displayname varalble for the rest of the code
$displayname = false;
$instructor = false;
if ( isset($_SESSION['lti']) ) {
    $lti = $_SESSION['lti'];
    $displayname = $lti['user_displayname'];
    $instructor = isInstructor($_SESSION['lti']);
}

// Check if this has a due date..
$duedate = false;
$duedatestr = false;
$diff = -1;
$penalty = false;
if ( isset($_SESSION['due']) ) {
	date_default_timezone_set('Pacific/Honolulu'); // Lets be generous
	if ( isset($_SESSION['timezone']) ) {
		date_default_timezone_set($_SESSION['timezone']);
	}
    $duedate = strtotime($_SESSION['due']);
	if ( $duedate !== false ) {
		$duedatestr = $_SESSION['due'];
		//  If it is just a date - add nearly an entire day of time...
		if ( strlen($duedatestr) <= 10 ) $duedate = $duedate + 24*60*60 - 1;
		$diff = time() - $duedate;
	}
}

// Should be a percentage off between 0.0 and 1.0
if ( $duedate && $diff > 0 ) {
	$penalty_time = isset($_SESSION['penalty_time']) ? $_SESSION['penalty_time'] + 0 : 24*60*60;
	$penalty_cost = isset($_SESSION['penalty_cost']) ? $_SESSION['penalty_cost'] + 0.0 : 0.2;
	$penalty_exact = $diff / $penalty_time;
	$penalties = intval($penalty_exact) + 1;
    $penalty = $penalties * $penalty_cost;
	if ( $penalty < 0 ) $penalty = 0;
	if ( $penalty > 1 ) $penalty = 1;
	$dayspastdue = $diff / (24*60*60);
	$percent = intval($penalty * 100);
	echo('<p style="color:red">It is currently '.sprintf("%10.2f",$dayspastdue)." days\n");
	echo('past the due date ('.htmlentities($duedatestr).') so your penalty is '.$percent." percent.\n");
	echo("This autograder sends the <em>latest</em> grade <b>not</b> the highest grade. So if you re-send\n");
	echo("a grade after the due date, your score in the LMS might go down.</p>\n");
}

function getUrl($sample) {
	global $displayname;
	global $instructor;
    global $pdo;
	if ( isset($_GET['url']) ) {
        echo('<p><a href="#" onclick="window.location.href = window.location.href; return false;">Re-run this test</a></p>'."\n");
        if ( isset($_SESSION['lti']) ) {
            $json = json_encode(array("url" => $_GET['url']));
            $retval = updateGradeJSON($pdo, $json);
        }
        return $_GET['url'];
    }

	if ( $displayname ) {
		echo("<p>&nbsp;</p><p><b>Hello $displayname</b> - welcome to the autograder.</p>\n");
	}

	if ( $instructor ) {
		echo('<p><a href="grades.php" target="_blank">Grade detail</a></p>'."\n");
    }

	echo('<form>
		Please enter the URL of your web site to grade:<br/>
		<input type="text" name="url" value="'.$sample.'" size="100"><br/>
		<input type="checkbox" name="grade">Send Grade (leave unchecked for a dry run)<br/>
		<input type="submit" value="Evaluate">
		</form>');
	if ( $displayname ) {
		echo("By entering a URL in this field and submitting it for 
		grading, you are representing that this is your own work.  Do not submit someone else's
		web site for grading.
		");
	}

	echo("<p>You can run this autograder as many times as you like and the last submitted
	grade will be recorded.  Make sure to double-check the course Gradebook to verify
	that your grade has been sent.</p>\n");
	exit();
}

function testPassed($grade, $url) {
	global $displayname;

	success_out("Test passed - congratulations");

	if ( $displayname === false || ! isset($_SESSION['lti']) ) {
		line_out('Not setup to return a grade..');
		exit();
	}
	
	if ( ! isset($_GET['grade']) ) {
		line_out('Dry run - grade of ('.intval($grade*100).'%) was not sent.');
		exit();
	}

    global $pdo;
    $json = json_encode(array("url" => $url));
    $debuglog = array();
    $retval = sendGradeDetail($grade, null, $json, $debuglog, $pdo, false);
    dumpGradeDebug($dumplog);
	if ( $retval == true ) {
		$success = "Grade sent to server (".intval($grade*100)."%)";
	} else if ( is_string($retval) ) {
		$failure = "Grade not sent: ".$retval;
	} else {
		echo("<pre>\n");
		var_dump($retval);
		echo("</pre>\n");
		$failure = "Internal error";
	}

	if ( strlen($success) > 0 ) {
		success_out($success);
		error_log($success);
	} else if ( strlen($failure) > 0 ) {
		error_out($failure);
		error_log($failure);
	} else {
		error_log("No status");
	}
}

function checkTitle($crawler) {
    global $displayname;
    if ( $displayname === false ) return true;

    try {
        $title = $crawler->filter('title')->text();
    } catch(Exception $ex) {
        return "Did not find title tag";
    }
    if ( strpos($title,$displayname) === false ) {
        return "Did not find '$displayname' in title tag";
    }
    return true;
}
