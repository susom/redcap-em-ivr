<?php
namespace Stanford\IVRStandAlone;
/** @var \Stanford\IVRStandAlone\IVRStandAlone $module */

$SCRIPT_TEMPLATE_ZIP = $module->getUrl("docs/IVR_Script_Sample_Instrument.zip");
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
?>

<div style='margin:20px 0;'>
    <h2>Stand alone REDCap + IVR EM Requirements</h2>
    <p>This EM will create an Interactive Voice Response script tree from an instrument in the host REDCap project.</p>

    <h5>Basic Rules for creating the instrument will need to be followed in creating the script.</h5>
    <ul>
        <li>Use dedicated instrument in the project to be the script (and then set in EM setting)</li>
        <li>Script should flow TOP to BOTTOM</li>
        <li>Use [descriptive] fields for speaking text only</li>
        <li>Use [field_label] fields for speaking text that immedietely precedes input prompts</li>
        <li>Double carriage return in [field_label] will add an automatic 1 second pause</li>
        <li>Script MUST end with a [descriptive] field to end the call eg. "Thank you , Good bye"</li>
        <li>There will be a 1 second pause between fields/script steps</li>
        <li>[field_note] MUST be used to describe number of [expected_digits] as well as set [voicemail] settings in JSON FORMAT!</li>
        <li>{{Special Instructions Syntax}} instructions can be used within speaking text and will be parsed/removed and acted on</li>
        <li>ONLY [descriptive], [text], [radio/truefalse/yesno], [dropdown] fields may be used... NO [checkbox]</li>
        <li>Script will honor any valid redcap branching  logic</li>
        <li>when [expected_digits] > 1 the text "Followed by the 'pound' sign." will be automatically inserted after the [field_label] prompt text, so plan accordingly</li>
        <li>when using [radio/truefalse/yesno/dropdown] fields with preset number/value options.  The caller will be prompted in the following manner "For [value] press [number]" in a loop with .5 second pause in between.  eg. "For Cats press 1 ,  For Dogs press 2"</li>
        <li>Each IVR Project needs these two specific records somewhere in them [caller_phone_number] and [vm_transcription] with "@IGNORE" annotations</li>
    </ul>
    
    <h5>Reference</h5>
    <p>Currently Recognized {{Special Instructions}}</p>
    <ul>
        <li>{{PAUSE=n}} : input an n second pause eg. {{PAUSE=3}} or {{PAUSE=.5}} for a 3 second and a half second pause respectively</li>
        <li>{{CALLFORWARD=xyz}} : Forward current call to 10 digit phone number (caller id will show call coming from your phone) eg. {{CALLFORWARD=4155551234}}</li>
    </ul>
    <p>Currently Recognized field_note JSON</p>
    <ul>
        <li>{"expected_digits" : n} : this step of the script expects n number of input digits eg. {"expected_digits" : 10} when asking for a 10 digit phone number</li>
        <li>{"voicemail" : {"timeout" : x, "length" : y}} : This will prompt the caller to leave a voice recording with either an x number of seconds to record or a y number of seconds length recording.  This will immedietely jump the entire script to the last step after prompting for a recording and end the call.  </li>
    </ul>


    <h5>TODO</h5>
    <ul>
        <li><em>change the em config to be manual input instead of dropdown of manuall curated list of options</em></li>
        <li>{{READLETTERS=abc}} : will read the text as "AYY BEE SEE"</li>
        <li>See if there is a "hangup" EVENT to more reliably remove the temporary "session" data, NO Postback on hangup... hmm</li>
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