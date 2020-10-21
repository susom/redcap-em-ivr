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
// $module->emDebug("POST FROM TWILIO callSID", $_POST);

// CALL TEMP STORAGE - PERSISTS THROUGH OUT CALL (starts empty);
$call_vars 	= $module->getTempStorage($temp_call_storage_key);

// FIRST CONTACT / EMPTY CALL_VARS / SET UP CALL "SESSION"
if(empty($call_vars) || ( isset($_POST["CallStatus"]) && $_POST["CallStatus"] == "ringing" )){
	//load script and other setup vars into the call_vars / "SESSION"
	if($module->loadScript($temp_call_storage_key)){
		$call_vars 	= $module->getTempStorage($temp_call_storage_key);
		$module->emDebug("First contact Load up the call_vars = SESSION");
	}
}

//IF IT CAME FROM PREVIOUS STEP, THEN PROCESS THE CHOICES, SAVE TO SESSION
//EVERY "step" NEEDS TO FINISH WITH AN INPUT
if( !empty($call_vars["previous_step"]) ){
	// CHOICE is the input answering the Previous Step Prompt
	$prev_step 			= $call_vars["previous_step"];
	$prev_field 		= $call_vars["script"][$prev_step];  
	$causes_branching 	= $call_vars["branching"];

	$module->emDebug("Handle Results From Previous Step", $prev_step);

	$module->emDebug("call_Vars", $call_vars);
	//TODO NEED TO REPEAT STEP IF WRONG INPUT IS PUT IN  (NOT 1 of PRESET CHOICES)
	if( !empty($prev_field["preset_choices"]) &&  !array_key_exists($choice , $prev_field["preset_choices"]) ){
		$module->emDebug("unexpected input, repeat step", $prev_step);
		//need to make $prev_step => $new_current_step
		$call_vars["current_step"] 	= $prev_step;
		$call_vars["repeat"] 		= true;
	}else{
		// MONITOR THE POST FOR [Recording Sid] , [RecordingUrl] and send an email if it is present
		if(!empty($_POST["RecordingSid"]) && !empty($_POST["RecordingUrl"]) && $prev_field["voicemail"]){
			$recording_url 	= trim(filter_var($_POST["RecordingUrl"], FILTER_SANITIZE_STRING));
			// $subject 		= "Voice Mail Recording";
			// $msg 	 		= "<a href='".$recording_url."'>Click to listen to voicemail.</a>";
			// $module->sendEmail($subject, $msg);
			$choice 		= $recording_url;
		}

		$rc_var = $prev_step;
		$rc_val = $choice;
		$module->setTempStorage($temp_call_storage_key , $rc_var, $rc_val );
		
		//SAVE WHAT WE HAVE SO FAR
		$call_vars[$rc_var] = $rc_val;
		$module->IVRHandler($call_vars);

		//handle branching
		if( array_key_exists( $prev_step ,$causes_branching) ){
			//If PREVIOUS STEP CAUSES BRANCHING, MATCH INPUT VALUE TO FIND NEXT STEP AND OVER WRITE call_Vars["next_step"];
			$new_current_step 	= $causes_branching[$prev_step][$choice];
			$call_vars["current_step"] 	= $new_current_step;
			$module->emDebug("Branching causing new step", $new_current_step);
		}
	}
}

// FIND THE CURRENT STEP AND CONSTRUCT THE APPROPRIATE IVR RESPONSE XML
$response 	= new VoiceResponse;
$module->getCurrentIVRstep($response, $call_vars);


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
