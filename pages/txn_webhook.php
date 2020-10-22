<?php
namespace Stanford\IVRStandAlone;
/** @var \Stanford\IVRStandAlone\IVRStandAlone $module */

$module->emDebug("transcription callback", $_POST);
if(isset($_POST["RecordingUrl"]) && isset($_POST["TranscriptionStatus"]) && $_POST["TranscriptionStatus"] == "completed"){
    $txn_recording_url  = trim($_POST["RecordingUrl"]);
    $txn_text           = trim($_POST["TranscriptionText"]); 
    $module->vmTranscriptionPostbackHandler($txn_recording_url, $txn_text);
}
exit;
?>