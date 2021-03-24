<?php
namespace Stanford\IVRStandAlone;

require_once "emLoggerTrait.php";

class IVRStandAlone extends \ExternalModules\AbstractExternalModule {
    use emLoggerTrait;

    // Fields in ACCESS CODE Project -> self::VAR
    const FIELD_ACCESS_CODE         = '123';

    // Private vars $this->var
    private   $script_instrument, $ivr_language, $ivr_voice;

    // This em is enabled on more than one project so you set the mode depending on the project -> self::$MODE  wtf?
    static $MODE;  // access_code_db, kit_order, kit_submission

    public function __construct() {
		parent::__construct();
		// Other code to run when object is instantiated
	    if (defined(PROJECT_ID)) { }
    }

    function redcap_every_page_top($project_id){
		// every page load
    }
    
    /** 
     * GET DESIGNATED SCRIPT PROJECT XML
     * @return array
     */
    public function loadScript($storage_key=null){
        //GET EM SETTINGS
        $this->script_instrument    = $this->getProjectSetting("active_instrument");
        $this->ivr_language         = $this->getProjectSetting("ivr_language");
        $this->ivr_voice            = $this->getProjectSetting("ivr_voice");

        //GET THE DICTIONARY WITH SCRIPT
        $dict               = \REDCap::getDataDictionary(PROJECT_ID, "array");
        
        //MANIPULATE THE SCRIPT 
        $next_step          = null;
        $new_script         = array();

        $backwards          = array_reverse($dict);
        $last_step          = current($backwards);

        foreach($dict as $field_name => $field){
            // the important stuff
            $form_name          = $field["form_name"];
            $field_type         = $field["field_type"];
            $annotation_arr     = explode(" ", trim($field["field_annotation"]));
            $field_label        = $field["field_label"];
            $choices            = $field["select_choices_or_calculations"];
            $branching_logic    = $field["branching_logic"];

            $field_note         = json_decode($field["field_note"],1); //MUST BE JSON
            $expected_digits    = array_key_exists("expected_digits", $field_note) ? $field_note["expected_digits"] : null;
            $voicemail_opts     = array_key_exists("voicemail", $field_note) ? $field_note["voicemail"] : null;

            // GET FIELDS FROM INSTRUMENT CONTAINING IVR SCRIPT
            if( $form_name == $this->script_instrument && !in_array("@IGNORE",$annotation_arr)){
                //PROCESS PRESET CHOICES 
                $preset_choices = array();
                if($field_type == "yesno" || $field_type  == "truefalse" || $field_type  == "radio" || $field_type == "dropdown"){
                    if($field_type == "yesno"){
                        $choices = "1,Yes | 0,No";
                    }
                    if($field_type == "truefalse"){
                        $choices = "1,True | 0,False";
                    }

                    //THESE WILL HAVE PRESET # , Choice Values
                    $choice_pairs   = explode("|",$choices);
                    foreach($choice_pairs as $pair){
                        $num_val = explode(",",$pair);
                        $preset_choices[trim($num_val[0])] = trim($num_val[1]);
                    } 
                }

                if( in_array("@NOREAD",$annotation_arr) ){
                    $field_label = "";
                    $annotation  = "@NOREAD";
                }

                if(in_array("@SOUNDFILE",$annotation_arr)){
                    $field_label = "";
                    $annotation  = "@SOUNDFILE";
                }

                //SET UP INITIAL "next_step"  IF ANY KIND OF BRANCHING IS INVOLVED WONT BE RELIABLE
                $new_script[$field_name]  = array(
                    "field_name"        => $field_name,
                    "field_type"        => $field_type,
                    "say_text"          => $field_label,
                    "preset_choices"    => $preset_choices,
                    "voicemail"         => $voicemail_opts,
                    "expected_digits"   => $expected_digits,
                    "branching_logic"   => $branching_logic,
                    "annotation"        => $annotation
                );
            }
        }

        // $this->emDebug("new script", $new_script);
        
        //SET UP THE INITIAL call_vars SESSION VARIABLES to run the script
        if(!is_null($storage_key)){
            $this->setTempStorage($storage_key, "ivr_language", $this->ivr_language);
            $this->setTempStorage($storage_key, "ivr_voice", $this->ivr_voice);
            $this->setTempStorage($storage_key, "script_instrument", $this->script_instrument); 
            $this->setTempStorage($storage_key, "ivr_dictionary_script", $new_script);

            $this->setTempStorage($storage_key, "storage_key", $storage_key);
            $this->setTempStorage($storage_key, "last_step", $last_step["field_name"]);
            $this->setTempStorage($storage_key, "previous_step", null);
           
            $first_step = current($new_script);
            $this->setTempStorage($storage_key, "current_step", $first_step["field_name"]);

            //Lets PreGenerate a Record, So it wont need to do it later...
            // $next_id        = $this->getNextAvailableRecordId(PROJECT_ID);
            // $this->setTempStorage($storage_key, "record_id", $next_id);
            
            // $startTS    = microtime(true);
            // $data = array("record_id" => $next_id); 
            // $r    = \REDCap::saveData('json', json_encode(array($data)) );
            // if(empty($r["errors"])){
            //     $this->emDebug("pregenerated record id $next_id", microtime(true) - $startTS);
            // }
        }

        return $new_script;
    }

    public function recurseCurrentSteps($current_step, $call_vars, $container){
        $this_step          = $current_step["field_name"];
        $field_type         = $current_step["field_type"];
        $branching_logic    = $current_step["branching_logic"];
        $record_id          = isset($call_vars["record_id"]) ? $call_vars["record_id"] : null;

        // WE ONLY RECURSE IF ITS A NON INPUT /DEscriptive Field
        if($field_type == "descriptive"){
            if( !empty($branching_logic) ){
                //has branching
                if($record_id){
                    //has record_id
                    $valid = \REDCap::evaluateLogic($branching_logic, PROJECT_ID, $record_id); 
                    if($valid){
                        // $this->emDebug("descriptive with branching, branching valid, have record_id" , $this_step, $branching_logic);
                        array_push($container, $current_step);
                    }
                }
            }else{
                array_push($container, $current_step);
            }

            $next = false;
            foreach($call_vars["ivr_dictionary_script"] as $field_name => $next_step){
                //keep iterating until we reach this step
                if($field_name == $this_step){
                    //mark it and continue to next step to evaluate
                    $next = true;
                    continue; //to next element
                }

                if($next){
                    $container = $this->recurseCurrentSteps($next_step, $call_vars, $container);
                    break;
                }
            }
        }else{
            if( !empty($branching_logic) ){
                // $this->emDebug("not descriptive field type looking for why VM inhertiting annotation of SOUNDFILE", $call_vars["ivr_dictionary_script"]["recording_all_2"]);
                //has branching
                if($record_id){
                    //has record_id
                    $valid = \REDCap::evaluateLogic($branching_logic, PROJECT_ID, $record_id); 
                    if($valid){
                        array_push($container, $current_step);
                    }else{
                        foreach($call_vars["ivr_dictionary_script"] as $field_name => $next_step){
                            //keep iterating until we reach this step
                            if($field_name == $this_step){
                                //mark it and continue to next step to evaluate
                                $next = true;
                                continue; //to next element
                            }
            
                            if($next){
                                $container = $this->recurseCurrentSteps($next_step, $call_vars, $container);
                                break;
                            }
                        }
                    }
                }
            }else{
                // this step is descriptive add and move on
                array_push($container, $current_step);
            }
        }
        // $this->emDebug("which step and and what $this_step", $container);
        return $container;
    }

    /** 
     * FROM CURRENT STATE, PRODUCE THE CURRENT STEP OF THE SCRIPT
     * @return array
     */
    public function getCurrentIVRstep($response, $call_vars){
        // $this->emDebug("HANDLE CURRENT STEP", $call_vars["previous_step"], $call_vars["current_step"]);

        // THIS IS THE CURRENT STEP, BUT THERE MAY BE OTHER STEPS TO FLOW DOWN TO IF THIS IS ONLY A DESCRIPTIVE TEXT
        $this_step          = $call_vars["current_step"];
        $current_step       = $call_vars["ivr_dictionary_script"][$this_step];
        
        // GATHER UP STEPs UNTIL REACHING An input step (evaluate branching if need be)
        $total_fields_in_step = $this->recurseCurrentSteps($current_step, $call_vars, array());
        $this->emDebug("FIELDS IN CURRENT STEP", $total_fields_in_step);

        $say_arr = array();
        foreach($total_fields_in_step as $step){
            $current_step_name      = $step["field_name"];
            $current_step_type      = $step["field_type"];
            $current_step_say       = $step["say_text"];
            $current_step_choices   = $step["preset_choices"];
            $current_step_vm        = $step["voicemail"];
            $current_step_expected  = $step["expected_digits"];
            $current_annotation     = $step["annotation"];

            // SPLIT UP "say" text into discreet say blocks by line break 
            // parse any special {{instructions}} 
            // say each line with a .5 second pause in between
            $temp_say       = explode(PHP_EOL, $current_step_say );
            foreach($temp_say as $say_line){
                if(!empty(trim($say_line))){
                    preg_match_all("/{{([\d\w\s]+)(?:(?:=?)([^}]+))?}}/" ,$say_line, $match_arr);
                    if(!empty($match_arr[0])){
                        $action = $match_arr[1][0];
                        $value  = is_numeric($match_arr[2][0]) ? ceil($match_arr[2][0]) : $match_arr[2][0];
                        
                        if($action == "PAUSE"){
                            $say_arr[] = array("pause" => $value);
                        }else if($action == "CALLFORWARD"){
                            $say_arr[] = array("dial" => $value);
                        }else{
                            continue;
                        }
                    }else{
                        $say_arr[] = array("say" => $say_line);
                    }
                }
            }

            if($current_annotation == "@SOUNDFILE"){
                $url = $this->handleSoundFiles($current_step_name);
                if(!empty($url)){
                    $say_arr[] = array("play" => $url);
                }
            }
            //WILL ALWAYS END UP ON AN INPUT, WILL ONLY BE MULTIPLE IF SOME ARE descriptive fields
            //SO WE CAN COME OUT OF THE LOOP ON THE correct current_step
        }

        // $this->emDebug("all of the says in the combined steps", $say_arr);

        $presets        = $current_step_choices;
        $voicemail      = $current_step_vm;
        $expected_digs  = $current_step_expected;
        $annotation     = $current_annotation;
        
        
        //may need to repeat
        if(array_key_exists("repeat" , $call_vars)){
            array_unshift($say_arr, array("say" => "The previous input was unexpected. Please try again.") );
        }


        //NEED TO FIND THE NEXT STEP IN THE FLOW , JUST PICK THE NEXT ONE DOWN , Recursive function will figure it out later
        $next_step  = null;
        $next       = false;
        foreach($call_vars["ivr_dictionary_script"] as $step_name => $step){
            if($step_name == $current_step_name){
                $next = true;
                continue;
            }             

            if($next){
                $next_step = $step["field_name"];
                break;
            }
        }
       
        $speaker        = $call_vars["ivr_voice"];
        $accent         = $call_vars["ivr_language"];
        $voicelang_opts = array('voice' => $speaker, 'language' => $accent);
        $gather_options = array('numDigits' => $expected_digs);
        
        if($annotation != "@NOREAD" && $annotation != "@SOUNDFILE"){
            if($expected_digs > 1){
                $gather_options["finishOnKey"] = "#";
                array_push($say_arr, array("say" => "Followed by the pound sign.") );
            }
            if(!empty($voicemail)){
                $gather_options["finishOnKey"] = "#";
                array_push($say_arr, array("say" => "When you are finished recording press the pound sign.") );
            }
        }

        //PAUSE TO BREATHE
        $response->pause(['length' => 1]);

        //SET UP GATHER , EVERY STEP MUST END IN gather
        if(empty($current_step_vm)){
            // $gather_options[] = "";
            // gotta be careful using gather method, it really seems to add a long pause when jumping back to response object context
            $gather = $response->gather($gather_options); 
        }
    
       

        // SAY EVERYTHING IN THE SAY BLOCK FIRST (OR DIAL OR PAUSE)
        $second_last    = count($say_arr) - 1;
        foreach($say_arr as $i =>  $method_value){
            if(array_key_exists("pause", $method_value) ){
                $pause_value = ceil($method_value["pause"]);
                $response->pause(["length" => $pause_value ]);  
            }else if(array_key_exists("dial", $method_value) ){
                $response->dial($method_value["dial"]);  
                //RETURN HERE CAUSE WE ARENT COMING BACK TO THIS CALL SESSION
                return;
            }else if(array_key_exists("play", $method_value) ){
                if(is_null($gather)){
                    $gather = $response->gather();
                    //must use "gather" instead of "response" so can cut off the audio with input and not have to play to end.
                }
                $url    = $method_value["play"];
                $loop   = $i == $second_last && empty($voicemail)  ? array("loop" => 3) : array(); 
                $this->emDebug("loop ", $loop);
                $gather->play($url, $loop);
            }else{
                if(!empty($current_step_vm)){
                    $response->say($method_value["say"], $voicelang_opts); 
                }else{
                    $gather->say($method_value["say"], $voicelang_opts); 
                }
                //THERE IS A NATURAL SMALL PAUSE BETWEEN DIFFERNT SAY BLOCKS
            }
        }
        //1 SET UP DIGITS REQUEST (EVERY STEP OF IVR MUST ASK FOR INPUT TO MOVE ON)
        //2 SAY OR PROMPT
        if(!empty($presets) && $annotation != "@NOREAD"){
            foreach($presets as $digit =>  $value){
                $prompt = "For $value press $digit";
                $gather->say($prompt, $voicelang_opts );
                $gather->pause(['length' => 1]);
            }
        }elseif(!empty($voicemail)){
            $this->emDebug("is this voicemail working?", $current_step_vm, $voicemail);
            $txn_webhook = $this->getURL("pages/txn_webhook.php",true,true);
            $response->record(['timeout' => $voicemail["timeout"], 'maxLength' => $voicemail["length"], 'transcribeCallback' => $txn_webhook, "finishOnKey" => "#"]);
        }
        
        //3 RESET CALL VARS FOR NEXT IVR STEP
        $storage_key = $call_vars["storage_key"];
        $this->setTempStorage($storage_key, "previous_step", $current_step_name);
        $this->setTempStorage($storage_key, "current_step", $next_step);
    }

    /**
     * STORES ALL THE RELEVANT DATA FROM call_vars WITH MATCHING keys = valid field_names back to Redcap
     * @return null
     */
    public function IVRHandler($call_vars) {
        $data       = array();
        $startTS    = microtime(true);

        // SET A record_id IF NOT AVAILABLE
        if(!isset($call_vars["record_id"])){
            $storage_key    = $call_vars["storage_key"];
            $next_id        = $this->getNextAvailableRecordId(PROJECT_ID);
            $call_vars["record_id"] = $next_id;
            $this->setTempStorage($storage_key, "record_id", $next_id);
        }

        $script_fieldnames = \REDCap::getFieldNames($this->script_instrument);
        foreach($call_vars as $rc_var => $rc_val){
            if( !in_array($rc_var, $script_fieldnames) ){
                continue;
            }
            $data[$rc_var] = $rc_val;
        }

        $r    = \REDCap::saveData('json', json_encode(array($data)) );
        if(empty($r["errors"])){
            $this->emDebug("svaed step?", $data);
        }

        return $call_vars;
    }


    public function vmTranscriptionPostbackHandler($recording_url, $txn_text){
        $this->emDebug("find this txn url and save txn text", $recording_url, $txn_text);

        // $recording_url = "https://api.twilio.com/2010-04-01/Accounts/ACacac91f9bd6f40e13e4a4a838c8dffce/Recordings/RE70de02ef2fe947beea04be1ebd09c5e0";
        // $txn_text = "test test";

        //NEED TO FIND THE VAR NAME WHER THE RECORDING URL IS STORED IN THE ACTIVE INSTRUMENT
        $current_active_ivr_script = $this->loadScript();
        foreach($current_active_ivr_script as $field_name => $field){
            if( !empty($field["voicemail"]) ){
                $recording_var = $field_name;
                break;
            }
        }

        $filter     = "[$recording_var] = '" . $recording_url . "' ";
        $fields     = array("record_id", "caller_phone_number");
        $q          = \REDCap::getData('json', null , $fields  , null, null, false, false, false, $filter);
        $results    = json_decode($q,true);

        if(!empty($results)){
            $result     = current($results);
            $record_id  = $result["record_id"];
            $caller     = $result["caller_phone_number"];
            $data = array();
            $data["record_id"]          = $record_id;
            $data["vm_transcription"]   = $txn_text;
            $r = \REDCap::saveData('json', json_encode(array($data)) );
            // $this->emDebug("did it save txn? now send email", $r);

            $subject 		= "Voice Recording from $caller";
            $msg_arr        = array();
            $msg_arr[]      = "<p>From $caller [Redcap record_id = $record_id] was recieved.</p>";
            $msg_arr[]	    = "<p><a href='".$recording_url."'>Click to listen to voicemail.</a></p>";
            $msg_arr[]      = "<p>Here is computer generated voice transcription (accuracy varies)<p>";
            $msg_arr[]      = "<blockquote>$txn_text</blockquote>";
            
            $to     = $this->getProjectSetting("vm_email");
            $from   = $this->getProjectSetting("vm_email_from");
            
            if(empty($to)){
                return;
            }
            
            $e = \REDCap::email($to, $from , $subject, implode("\r\n", $msg_arr));
            if($e){
                $this->emDebug("email succesfully sent", $e);
            }
        }
    }

    public function handleSoundFiles($step_name){        
        //EXTRACT THE EDOC INFORMATION FROM RC DB, NO BUILT IN METHODS TO GET THIS DATA
        $sql        = "SELECT doc_id, stored_name, mime_type, doc_name, doc_size, file_extension, stored_date 
                        FROM redcap_metadata INNER JOIN redcap_edocs_metadata
                        ON redcap_metadata.edoc_id = redcap_edocs_metadata.doc_id 
                        WHERE redcap_metadata.project_id = ? AND redcap_metadata.field_name = ?";
        $parameters = array(PROJECT_ID, $step_name);

        //TODO Better way to do this and check for errors?
        $results    = $this->framework->query($sql, $parameters);
        while ($row = $results->fetch_assoc()) {
            if(!empty($row)){
                $audio_file = $this->getEdocAssetUrl($row);
                return $audio_file;
                break;
            }
        }
        return false;
    }

    /*
        Pull static files from within EM dir Structure
    */
    function getEdocAssetUrl($file_info, $hard_domain="https://eb386f1cd653.ngrok.io"){
        $doc_id         = $file_info["doc_id"];
        $mime_type      = $file_info["mime_type"];

        // Dont need
        // $stored_name    = $file_info["stored_name"];
        // $doc_name       = $file_info["doc_name"];
        // $doc_size       = $file_info["doc_size"];
        // $file_extension = $file_info["file_extension"];
        // $stored_date    = $file_info["stored_date"];

        $doc_temp_path      = \Files::copyEdocToTemp($doc_id, false, true);
        // TODO NEED TO UNLINK THE TEMP FILE PATH
        // MAYBE PASS IN ARRAY WITH Call_vars and delete on hang up ?
        // unlink($doc_temp_path);
        
        $qs = array(
             "mime_type=$mime_type"
            ,"doc_temp_path=$doc_temp_path"
        );

        $getEdocAsset   = "getEdocAsset.php?" . implode("&",$qs);
        $audio_file     = $this->framework->getUrl($getEdocAsset , true, true);

        if(!empty($hard_domain)){
            $audio_file = str_replace("http://localhost",$hard_domain, $audio_file);
        }
        
        // $this->emDebug("The NO AUTH URL FOR AUDIO FILE", $audio_file); 
        return $audio_file;
    }

    /**
     * Set Temp Store Proj Settings
     * @param $key 
     */
    public function setTempStorage($storekey, $k, $v) {
        if(!is_null($storekey)){
            $temp = $this->getTempStorage($storekey);
            $temp[$k] = $v;
            $this->setProjectSetting($storekey, json_encode($temp));
        }
        return; 
    }

    /**
     * Get Temp Store Proj Settings
     * @param $key 
     */
    public function getTempStorage($storekey) {
        if(!is_null($storekey)){
            $temp = $this->getProjectSetting($storekey);
            $temp = empty($temp) ? array() : json_decode($temp,1);
        }else{
            $temp = array();
        }
        return $temp;
    }

    /**
     * rEMOVE Temp Store Proj Settings
     * @param $key 
     */
    public function removeTempStorage($storekey) {
        $this->removeProjectSetting($storekey);
        return;
    }
    

    /**
     * GET Next available RecordId in a project
     * @return bool
     */
    public function getNextAvailableRecordId($pid){
        $pro                = new \Project($pid);
        $primary_record_var = $pro->table_pk;

        $q          = \REDCap::getData($pid, 'json', null, $primary_record_var );
        $results    = json_decode($q,true);
        if(empty($results)){
            $next_id = 1;
        }else{
            $last_entry = array_pop($results);
            $next_id    = $last_entry[$primary_record_var] + 1;
        }

        return $next_id;
    }

}
?>