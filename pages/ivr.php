<?php
namespace Stanford\IVRStandAlone;
/** @var \Stanford\IVRStandAlone\IVRStandAlone $module */

require $module->getModulePath().'vendor/autoload.php';
use Twilio\TwiML\VoiceResponse;

/*
0. Every POST Back to the page is an INDEPENDENT EVENT.   NO $_SESSION available
1. Load the meta data from the instrument (must be configured in EM Settings)
2. Use EM Setting var to store data until end. OR save after every response
3. the [Digits] Post var carrys number input from previous step
4. call_vars will hold data from previous step
*/

// POST FROM TWILIO
$temp_call_storage_key 	= trim(filter_var($_POST["CallSid"], FILTER_SANITIZE_STRING));
$choice 				= isset($_POST["Digits"]) 	? trim(filter_var($_POST["Digits"], FILTER_SANITIZE_NUMBER_INT)) : null;
$module->emDebug("POST FROM TWILIO callSID", $_POST);

// CALL TEMP STORAGE - PERSISTS THROUGH OUT CALL (starts empty);
$call_vars 	= $module->getTempStorage($temp_call_storage_key);

// FIRST CONTACT / EMPTY CALL_VARS / SET UP CALL "SESSION"
if(empty($call_vars) || ( isset($_POST["CallStatus"]) && $_POST["CallStatus"] == "ringing" )){
	//load script and other setup vars into the call_vars / "SESSION"
	if($module->loadScript($temp_call_storage_key)){
		$call_vars 	= $module->getTempStorage($temp_call_storage_key);
	}
}

//IF IT CAME FROM PREVIOUS STEP, THEN PROCESS THE CHOICES, SAVE TO SESSION
if( !empty($call_vars["previous_step"]) ){
	$prev_step 	= $call_vars["previous_step"];
	$field 		= $call_vars["script"][$prev_step];  
	
	// MONITOR THE POST FOR [Recording Sid] , [RecordingUrl] and send an email if it is present
	if(!empty($_POST["RecordingSid"]) && !empty($_POST["RecordingUrl"]) && $field["voicemail"]){
		$recording_url 	= trim(filter_var($_POST["RecordingUrl"], FILTER_SANITIZE_STRING));
		// $subject 		= "Voice Mail Recording";
		// $msg 	 		= "<a href='".$recording_url."'>Click to listen to voicemail.</a>";
		// $module->sendEmail($subject, $msg);
		$choice 		= $recording_url;
	}

	$rc_var = $prev_step;
	$rc_val = $choice;
	$module->setTempStorage($temp_call_storage_key , $rc_var, $rc_val );
}

// FIND THE CURRENT STEP AND CONSTRUCT THE APPROPRIATE IVR RESPONSE XML
$response 	= new VoiceResponse;
$module->getCurrentIVRstep($response, $call_vars);

// if(isset($_POST["CallStatus"]) && $_POST["CallStatus"] == "in-progress" && false){
// 	// ALL SUBSEQUENT RESPONSES WILL HIT THIS SAME ENDPOINT , DIFFERENTIATE ON "action"
// }

// ONCE FALLS THROUGH THE REST OF THE SCRIPT, HANG UP
print($response);
$response->pause(['length' => 1]);
$response->hangup();

if($call_vars["current_step"] == $call_vars["last_step"]){
	$all_vars = $module->getTempStorage($temp_call_storage_key);
	
	//SAVE ALL DATA TO REDCAP
	$module->IVRHandler($all_vars);

	//REMOVE CALL "SESSION"
    $module->removeTempStorage($temp_call_storage_key);
}
exit();