<?php
namespace Stanford\IVRStandAlone;
/** @var \Stanford\IVRStandAlone\IVRStandAlone $module */

$SCRIPT_TEMPLATE_ZIP = $module->getUrl("docs/IVR_Script_Sample_Instrument.zip");
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
?>

<div style='margin:20px 0;'>
    <h2>Stand alone REDCap + IVR EM Requirements</h2>
    <p>This EM will create an Interactive Voice Response script tree from an instrument in the host REDCap project.</p>
    
    <h5>Rules for creating the instrument will need to be followed in creating the script.</h5>
    <ul>
        <li>Use dedicated instrument in the project to be the script (and then set in EM setting)</li>
        <li>Script should flow TOP to BOTTOM</li>
        <li>Use [descriptive] fields for speaking text</li>
        <li>Script MUST end with a [descriptive] field to end the call "Thank you , Good bye"</li>
        <li>[field_note] MUST be used to describe how many digit inputs are required</li>
        <li>ONLY [descriptive], [text], [radio/truefalse/yesno], [dropdown] fields may be used... NO [checkbox]</li>
        <li>Script will honor BASIC branching (on single condition)</li>
        <li>USE "@VOICEMAIL" annotation on a [text] field  to ask for voice recording , WHICH will skip to end of script when done</li>
    </ul>
    
    <h5>TODO</h5>
    <ul>
        <li><em>change the em config to be manual input instead of dropdown of manuall curated list of options</em></li>
        <li><em>Get Branching Logic Working</em></li>
    </ul>


    <h5>Sample Script Instrument Format - XML Templates:</h5>
    <ul>
    <li><?php echo "<a href='$SCRIPT_TEMPLATE_ZIP'>Sample IVR Script Instrument template [.ZIP]</a>" ?></li>
    </ul>

    <br>
    <br>
 
    <h3>Twilio Callback Endpoint</h3>
    <p>Please configure the twilio phone number voice calling webhook to the following url:</p>
    <pre class='mr-5'><?php echo $module->getUrl("pages/ivr.php",true, true ) ?></pre>

</div>