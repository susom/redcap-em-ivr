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
            $field_label        = $field["field_label"];
            $choices            = $field["select_choices_or_calculations"];
            $branching_logic    = $field["branching_logic"];

            $field_note         = json_decode($field["field_note"],1); //MUST BE JSON
            $expected_digits    = array_key_exists("expected_digits", $field_note) ? $field_note["expected_digits"] : null;
            $voicemail_opts     = array_key_exists("voicemail", $field_note) ? $field_note["voicemail"] : null;

            // GET FIELDS FROM INSTRUMENT CONTAINING IVR SCRIPT
            if( $form_name == $this->script_instrument ){
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

                //SET UP INITIAL "next_step"  IF ANY KIND OF BRANCHING IS INVOLVED WONT BE RELIABLE
                $new_script[$field_name]  = array(
                    "field_name"        => $field_name,
                    "field_type"        => $field_type,
                    "say_text"          => $field_label,
                    "preset_choices"    => $preset_choices,
                    "voicemail"         => $voicemail_opts,
                    "expected_digits"   => $expected_digits,
                    "branching_logic"   => $branching_logic,
                );
            }
        }

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
        }

        return true;
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

        return $container;
    }
    
    /** 
     * FROM CURRENT STATE, PRODUCE THE CURRENT STEP OF THE SCRIPT
     * @return array
     */
    public function getCurrentIVRstep($response, $call_vars){
        $this->emDebug("HANDLE CURRENT STEP", $call_vars["previous_step"], $call_vars["current_step"]);

        // THIS IS THE CURRENT STEP, BUT THERE MAY BE OTHER STEPS TO FLOW DOWN TO IF THIS IS ONLY A DESCRIPTIVE TEXT
        $this_step          = $call_vars["current_step"];
        $current_step       = $call_vars["ivr_dictionary_script"][$this_step];
        
        // GATHER UP STEPs UNTIL REACHING An input step (evaluate branching if need be)
        $total_fields_in_step = $this->recurseCurrentSteps($current_step, $call_vars, array());
        $this->emDebug("total fields in step", $total_fields_in_step);

        $say_arr = array();
        foreach($total_fields_in_step as $step){
            $current_step_name      = $step["field_name"];
            $current_step_type      = $step["field_type"];
            $current_step_say       = $step["say_text"];
            $current_step_choices   = $step["preset_choices"];
            $current_step_vm        = $step["voicemail"];
            $current_step_expected  = $step["expected_digits"];

            // SPLIT UP "say" text into discreet say blocks by line break 
            // parse any special {{instructions}} 
            // say each line with a .5 second pause in between
            $temp_say       = explode(PHP_EOL, $current_step_say );
            foreach($temp_say as $say_line){
                if(!empty(trim($say_line))){
                    preg_match_all("/{{([\d\w\s]+)(?:(?:=?)([^}]+))?}}/" ,$say_line, $match_arr);
                    if(!empty($match_arr[0])){
                        $action = $match_arr[1][0];
                        $value  = $match_arr[2][0];
                        
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

            //WILL ALWAYS END UP ON AN INPUT, WILL ONLY BE MULTIPLE IF SOME ARE descriptive fields
            //SO WE CAN COME OUT OF THE LOOP ON THE correct current_step
        }

        $this->emDebug("all of the says in the combined steps", $say_arr);

        $presets        = $current_step_choices;
        $voicemail      = $current_step_vm;
        $expected_digs  = $current_step_expected;
        
        
        //may need to repeat
        if(array_key_exists("repeat" , $call_vars)){
            array_unshift($say_arr, array("say" => "The previous input was unexpected. Please try again.") );
        }


        //NEED TO FIND THE NEXT STEP IN THE FLOW , JUST PICK THE NEXT ONE DOWN , FIGURE IT OUT LATER
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
        
        if($expected_digs > 1){
            $gather_options["finishOnKey"] = "#";
            array_push($say_arr, array("say" => "Followed by the pound sign.") );
        }
        if(!empty($voicemail)){
            $gather_options["finishOnKey"] = "#";
            array_push($say_arr, array("say" => "When you are finished recording press the pound sign.") );
        }

        //PAUSE TO BREATHE
        $response->pause(['length' => 1]);

        //SET UP GATHER , EVERY STEP MUST END IN gather
        $gather = $response->gather($gather_options); 

        // SAY EVERYTHING IN THE SAY BLOCK FIRST (OR DIAL OR PAUSE)
        foreach($say_arr as $method_value){
            if(array_key_exists("pause", $method_value) ){
                $gather->pause(["length" => $method_value["pause"] ]);  
            }else if(array_key_exists("dial", $method_value) ){
                $response->dial($method_value["dial"]);  
                //RETURN HERE CAUSE WE ARENT COMING BACK TO THIS CALL SESSION
                return;
            }else{
                $gather->say($method_value["say"], $voicelang_opts);  
                //THERE IS A NATURAL SMALL PAUSE BETWEEN DIFFERNT SAY BLOCKS
            }
        }

        //1 SET UP DIGITS REQUEST (EVERY STEP OF IVR MUST ASK FOR INPUT TO MOVE ON)
        //2 SAY OR PROMPT
        if(!empty($presets)){
            $gather = $response->gather($gather_options); 
            foreach($presets as $digit =>  $value){
                $prompt = "For $value press $digit";
                $gather->say($prompt, $voicelang_opts );
                $gather->pause(['length' => .5]);
            }
        }else if(!empty($voicemail)){
            $response->record(['timeout' => $voicemail["timeout"], 'maxLength' => $voicemail["length"], 'transcribe' => 'true', "finishOnKey" => "#"]);
        }
        
        //3 RESET CALL VARS FOR NEXT IVR STEP
        $storage_key = $call_vars["storage_key"];
        $this->setTempStorage($storage_key, "previous_step", $current_step_name);

        //TODO , FIND THE NEXT STEP
        $this->setTempStorage($storage_key, "current_step", $next_step);
    }

    /**
     * STORES ALL THE RELEVANT DATA FROM call_vars WITH MATCHING keys = valid field_names back to Redcap
     * @return null
     */
    public function IVRHandler($call_vars) {
        $data = array();

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
        // $this->emDebug("Did it save this step?", $data, $r , $call_vars);
        return;
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









    /*
        Pull static files from within EM dir Structure
    */
    function getAssetUrl($audiofile = "default.mp3", $hard_domain = ""){
        $audio_file = $this->framework->getUrl("getAsset.php?file=".$audiofile."&ts=". $this->getLastModified() , true, true);
        
        if(!empty($hard_domain)){
            $audio_file = str_replace("http://localhost",$hard_domain, $audio_file);
        }

        // $this->emDebug("The NO AUTH URL FOR AUDIO FILE", $audio_file); 
        return $audio_file;
    }
    
    /*
        USE mail func
    */
    public function sendEmail($subject, $msg, $from="Twilio VM", $to="this_project_admin@stanford.edu"){
        //boundary
        $semi_rand = md5(time());
        $mime_boundary = "==Multipart_Boundary_x{$semi_rand}x";

        //headers for attachment
        //header for sender info
        $headers = "From: "." <".$from.">";
        $headers .= "\nMIME-Version: 1.0\n" . "Content-Type: multipart/mixed;\n" . " boundary=\"{$mime_boundary}\"";

        //multipart boundary
        $message = "--{$mime_boundary}\n" . "Content-Type: text/html; charset=\"UTF-8\"\n" .
            "Content-Transfer-Encoding: 7bit\n\n" . $msg . "\n\n";

        if (!mail($to, $subject, $message, $headers)) {
            $this->emDebug("Email NOT sent");
            return false;
        }
        $this->emDebug("Email sent");
        return true;
    }
}
?>