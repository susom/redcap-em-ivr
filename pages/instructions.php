<?php
namespace Stanford\IVRStandAlone;
/** @var \Stanford\IVRStandAlone\IVRStandAlone $module */

$XML_KS_PROJECT_TEMPLATE = "";//$module->getUrl("docs/CAFACTSKITSUBMISSION_2020-06-23_1523.REDCap.xml");
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
?>

<div style='margin:20px 0;'>
    <h4>Stand alone REDCap + IVR EM Requirements</h4>
    <p>This EM will create a script tree from an instrument in the host REDCap project.</p>
    <p>Strict rules for creating the instrument will need to be followed.</p>
    <p>Script should honor basic branching?</p>
    <p>Descriptive Fields are [say] verbs</p>
    <p>field_note will be used to put number of expected digits for a response</p>
    <p>Checkbox, Radio and Dropdown are [gather] + [say]<p>
    <p>scratch that NO checkboxes</p>
    <p>branching will determine which script step to jump to next</p>


    <br>
    <br>

    <h5>Sample Instrument Format - XML Templates:</h5>
    <ul>
    <li><?php echo "<a href='$XML_AC_PROJECT_TEMPLATE'>Sample REDCap + IVR project template</a>" ?></li>
    </ul>

    <br>
    <br>
 
    <h4>Twilio Callback Endpoint</h4>
    <p>Please configure the twilio phone number voice calling webhook to the following url:</p>
    <pre class='mr-5'><?php echo $module->getUrl("pages/ivr.php",true, true ) ?></pre>

</div>