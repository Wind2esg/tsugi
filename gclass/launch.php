<?php

if ( ! isset($CFG) ) return; // Only from within tsugi.php

use \Tsugi\Util\U;
use \Tsugi\Core\LTIX;
use \Tsugi\Google\GoogleClassroom;

require_once("util.php");

$request_headers = apache_request_headers();
$agent = U::get($request_headers,'User-Agent');
if ( isset($CFG->logo_url) && $agent && stripos($agent,'Google Web Preview') !== false ) {
    echo('<center><img src="'.$CFG->logo_url.'"></center>'."\n");
    return;
}

session_start();

// Lets be really picky about the path...
$path = U::parse_rest_path();
if ( count($path) != 3 ) {
    $_SESSION['error'] = 'Invalid resource id format';
    header('Location: '.$CFG->apphome);
    return;
}
$pieces = explode(':',$path[2]);
if ( count($pieces) != 4 || strlen($pieces[1]) != 6 || strlen($pieces[3]) != 6 
     || ! is_numeric($pieces[0]) || ! is_numeric($pieces[2]) ) {
    $_SESSION['error'] = 'Invalid resource id format';
    header('Location: '.$CFG->apphome);
    return;
}

$gc_course = $pieces[0];
$user_mini_sig = $pieces[1];
$link_id = $pieces[2];
$link_mini_sig = $pieces[3];

$context_url = $gc_course . ':' . $user_mini_sig;
$context_key = 'gclass:' . $context_url;
$context_sha256 = lti_sha256($context_key);

if ( ! isset($_SESSION['id']) ) {
    $_SESSION['login_return'] = $path[0].'/'.$path[1].'/'.$path[2];
    header('Location: '.$CFG->apphome.'/login');
    return;
}

$user_id = $_SESSION['id'];
$user_email = $_SESSION['email'];
$user_displayname = $_SESSION['displayname'];
$user_key = $_SESSION['user_key'];
$user_avatar = U::get($_SESSION,'avatar', null);

$PDOX = LTIX::getConnection();

// Load up the stuff course / link stuff / big inner join

$sql = "SELECT gc_secret, O.user_id AS owner_id, O.email AS owner_email,
        role, L.path AS path, C.context_id AS context_id, C.title AS context_title,
        link_key, L.link_id as link_id, L.title AS link_title,
        result_id, gc_submit_id,
        K.key_id AS key_id, K.secret AS key_secret, K.key_key AS key_key
    FROM {$CFG->dbprefix}lti_context AS C
    JOIN {$CFG->dbprefix}lti_user AS O
        ON C.user_id = O.user_id
    JOIN {$CFG->dbprefix}lti_link AS L
        ON C.context_id = L.context_id
    JOIN {$CFG->dbprefix}lti_key AS K
        ON C.key_id = K.key_id
    LEFT JOIN {$CFG->dbprefix}lti_result AS R
        ON L.link_id = R.link_id AND R.user_id = :UID
    LEFT JOIN {$CFG->dbprefix}lti_membership AS M
        ON C.context_id = M.context_id AND M.user_id = :UID
    WHERE context_sha256 = :context_sha256 AND context_key = :context_key
    AND L.link_id = :LID
    LIMIT 1";

$row = $PDOX->rowDie($sql,
    array(
        ':context_sha256' => $context_sha256,
        ':context_key' => $context_key,
        ':UID' => $user_id,
        ':LID' => $link_id )
);

if ( ! $row ) {
    $_SESSION['error'] = 'Could not lookup resource id';
    header('Location: '.$CFG->apphome);
    return;
}

$gc_secret = $row['gc_secret'];
$path = $row['path'];
$owner_id = $row['owner_id'];
$gc_coursework = $row['link_key'];
$owner_email = $row['owner_email'];
$context_id = $row['context_id'];
$context_title = $row['context_title'];
$key_id = $row['key_id'];
$key_key = $row['key_key'];
$key_secret = $row['key_key'];
$gc_submit_id = $row['gc_submit_id'];
$result_id = $row['result_id'];
$resource_title = $row['link_title'];

// Do some validation...
$plain = $CFG->google_classroom_secret.$gc_course.$owner_id.$CFG->google_classroom_secret;
$user_mini_check = lti_sha256($plain);
$user_mini_check = substr($user_mini_check,0,6);

$plain = $gc_secret.$context_url.$link_id.$gc_secret;
$link_mini_check = lti_sha256($plain);
$link_mini_check = substr($link_mini_check,0,6);

if ( $link_mini_check != $link_mini_sig || $user_mini_check != $user_mini_sig ) {
    $_SESSION['error'] = 'Could not validate resource id';
    header('Location: '.$CFG->apphome);
    return;
}

// Time to talk to Google.

// Try access token from session when LTIX adds it.
$accessTokenStr = GoogleClassroom::retrieve_instructor_token($owner_id);
if ( ! $accessTokenStr ) {
    $_SESSION['error'] = 'Classroom connection not set up, see your instructor';
    header('Location: '.$CFG->apphome);
    return;
}

// Get the API client and construct the service object.
$client = GoogleClassroom::getClient($accessTokenStr, $owner_id);
if ( ! $client ) {
    $_SESSION['error'] = 'Classroom connection failed';
    error_log('Classroom connection failed id='.$owner_id);
    error_log($accessTokenStr);
    header('Location: '.$CFG->apphome);
    return;
}

// Lets talk to Google
$service = new Google_Service_Classroom($client);

// Get the user's profile information

$x = $client->getAccessToken();
$access_token = $x['access_token'];

$role = false;
if ( $user_email == $owner_email ) $role = LTIX::ROLE_INSTRUCTOR;

// Check if the current user is a student...
if ( ! $role ) {
    // v1/userProfiles/{userId}
    // https://classroom.googleapis.com/v1/courses/{courseId}/students/{userId}
    $membership_info_url = "https://classroom.googleapis.com/v1/courses/".$gc_course.
        "/students/".$_SESSION['email']."?alt=json&access_token=" .  $access_token;

    $response = \Tsugi\Util\Net::doGet($membership_info_url);
    $membership = json_decode($response);

    /* Not a student:
    object(stdClass)#27 (1) {
    ["error"]=>
        object(stdClass)#26 (3) {
        ["code"]=>
        int(404)
        ["message"]=>
        string(31) "Requested entity was not found."
        ["status"]=>
        string(9) "NOT_FOUND"
        }
    }
    
    Student:
    object(stdClass)#24 (3) {
    ["courseId"]=>
    string(10) "9523923149"
    ["userId"]=>
    string(21) "100516595241762316861"
    ["profile"]=>
    ...
    }
    */

    $student_id = false;

    // If the current user is a student we are golden
    if ( isset($membership->courseId) ) {
        $role = 0;
    } else {
        $_SESSION['error'] = 'You are not enrolled in this class';
        error_log('Classroom connection failed id='.$owner_id);
        error_log($accessTokenStr);
        header('Location: '.$CFG->apphome);
        return;
    }

    if ( isset($membership->userId) ) {
        $student_id = $membership->userId;
    } else {
        $_SESSION['error'] = 'You are do not have a studentId in this class';
        error_log('Classroom connection failed id='.$owner_id);
        error_log($accessTokenStr);
        header('Location: '.$CFG->apphome);
        return;
    }

}

// https://developers.google.com/classroom/guides/manage-coursework
// service.courses().courseWork().studentSubmissions().list(
//    courseId=<course ID or alias>,
//    courseWorkId='-',
//    userId="me").execute()

// Returns a student of a course.
// https://developers.google.com/classroom/reference/rest/v1/courses.students/get
// https://classroom.googleapis.com/v1/courses/{courseId}/students/{userId}

/*
service.courses().courseWork().studentSubmissions().list(  
    courseId=<course ID or alias>,  
    courseWorkId=<assignment ID>,  
    userId=<user ID>).execute()
*/

/*
studentSubmission = {  
  'assignedGrade': 99,  
  'draftGrade': 80  
}  
service.courses().courseWork().studentSubmissions().patch(  
    courseId=<course ID or alias>,  
    courseWorkId=<courseWork ID>,  
    id=<studentSubmission ID>,  
    updateMask='assignedGrade,draftGrade',  
    body=studentSubmission).execute()
*/

if ($role >= LTIX::ROLE_INSTRUCTOR) {
    echo("<h1>I am an instructor launch</h1>\n");
} else {
    echo("<h1>I am a student launch</h1>\n");
echo("<pre>\n");
// var_dump($service);
// $studentSubmissions = $service->studentSubmissions;
$studentSubmissions = $service->courses_courseWork_studentSubmissions;
echo("\n<hr>\n");
// var_dump($studentSubmissions);

$listx = $studentSubmissions->listCoursesCourseWorkStudentSubmissions($gc_course, $gc_coursework,
    array('userId' => $student_id));
// var_dump($listx);

$listy = $listx->studentSubmissions;
$first = $listy[0];
$submit_id = $first->id;
// var_dump($first);
echo("submit_id=$submit_id\n");

// https://developers.google.com/classroom/reference/rest/v1/courses.courseWork.studentSubmissions#SubmissionState
// $sub = new Google_Service_Classroom_StudentSubmission();

// https://stackoverflow.com/questions/43488498/google-classroom-api-patch
// According to the above - do a get() first - Did not change 
$sub = $studentSubmissions->get($gc_course, $gc_coursework, $submit_id);
echo("=====pre-patch\n");
var_dump($sub);
$sub->setAssignedGrade(70);
$sub->setDraftGrade(70);
$sub->setState('TURNED_IN');
$opt = array('updateMask' => 'assignedGrade,draftGrade');
$retval = $studentSubmissions->patch($gc_course, $gc_coursework, $submit_id, $sub, $opt);
echo("=====post-patch\n");
var_dump($retval);
}
echo("<p>Email:".htmlentities($user_email)."</p>\n");

// Set up the LTI launch information

$lti = array();
$lti['key_id'] = $key_id;
$lti['key_key'] = $key_key;

if ( strlen($key_secret) ) {
    $lti['secret'] = LTIX::encrypt_secret($key_secret);
}

$lti['user_id'] = $user_id;
$lti['user_key'] = $user_key;
$lti['user_email'] = $user_email;
$lti['user_displayname'] = $user_displayname;
if ( strlen($user_avatar) ) {
    $lti['user_image'] = $user_avatar;
}

$lti['context_title'] = $context_title;
$lti['resource_title'] = $resource_title;

$lti['context_id'] = $context_id;
$lti['context_key'] = $context_key;

// Set that data in the session.
$_SESSION['lti'] = $lti;

?>
<pre>
LTI:
<?php
print_r($lti);
?>
Row:
<?php
print_r($row);
?>
Path:
<?php
print_r($path);
?>

Post:
<?php
print_r($_POST);
?>
<hr/>
Get:
<?php
print_r($_GET);
?>
<hr/>
Apache Request Headers:
<?php
print_r($request_headers);
error_log(\Tsugi\UI\Output::safe_var_dump($request_headers));
