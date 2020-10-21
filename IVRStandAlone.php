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
        $basic_script       = array();
        $backwards          = array_reverse($dict);
        $script_dict        = array();
        $branch_effectors   = array();
        $next_step          = null;
        $last_step          = current($backwards);

        foreach($backwards as $field){
            // Its backwards so for the most part the "previous" step can be set as "next" (except when branching)
            // the important stuff
            $form_name          = $field["form_name"];
            $field_name         = $field["field_name"];
            $field_type         = $field["field_type"];
            $field_label        = $field["field_label"];
            $choices            = $field["select_choices_or_calculations"];

            $field_note         = json_decode($field["field_note"],1); //MUST BE JSON
            $expected_digits    = array_key_exists("expected_digits", $field_note) ? $field_note["expected_digits"] : null;
            $voicemail_opts     = array_key_exists("voicemail", $field_note) ? $field_note["voicemail"] : null;

            $branching_logic    = $field["branching_logic"];
            $annotation         = $field["field_annotation"];

            // GET FIELDS FROM INSTRUMENT CONTAINING IVR SCRIPT
            if( $form_name == $this->script_instrument ){
                $has_branching = false;
                if(!empty($branching_logic)){
                    $has_branching = true;
                    
                    preg_match_all("/\[(?<effector>[\w_]+)(\((?<checkbox_value>\d+)\))?\] = \'(?<value>\d+)\'/",$branching_logic, $matches);
                    
                    // possibly multiple Effector fields AND / OR
                    foreach($matches["effector"] as $i => $ef){
                        $checkbox_value = $matches["checkbox_value"][$i];
                        $value          = $matches["value"][$i];

						if(!array_key_exists($ef,$branch_effectors)){
							$branch_effectors[$ef] = array();
                        }

                        $branch_effectors[$ef][$value] = $field_name;
                    }
                  
					$andor  = "||"; //Defualt && , doesnt matter
					// if(count($effectors) == 1){
					// 	//then it doesnt matter it will be OR
					// 	//its mutually exclusive values for the same input(fieldname)
					// 	//so the $andor value is moot
					// }else{
					// 	preg_match_all("/(?<ors>\sor\s)/",$branching_logic, $matches);
					// 	$ors 	= count($matches["ors"]);
					// 	preg_match_all("/(?<ands>\sand\s)/",$branching_logic, $matches);
					// 	$ands 	= count($matches["ands"]);
						
					// 	if($ors && !$ands){
					// 		$andor = "||";
					// 		// print_rr($branching);
					// 		// print_rr($effectors);
					// 	}else if($ors && $ands){
					// 		//the multiple effector will take the "or" and the and is for the other
					// 	}//else its default 
					// }
                }

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
                $next_step = empty($branching_logic) ? $next_step : null;
                $next_step = empty($voicemail_opts)  ? $next_step : $last_step["field_name"];
                
                $script_dict[$field_name]  = array(
                    "field_name"        => $field_name,
                    "field_type"        => $field_type,
                    "say_text"          => $field_label,
                    "preset_choices"    => $preset_choices,
                    "voicemail"         => $voicemail_opts,
                    "expected_digits"   => $expected_digits,
                    "branching"         => $branching_logic,
                    "next_step"         => $next_step
                );

                $next_step = $field_name;
            }
        }

        $forwards                       = array_reverse($script_dict);
        $descriptive_say_previous_step  = "";
        $i                              = 1;
        $last_i                         = count($script_dict);
        foreach($forwards  as $field_name => $step){
            $field_type = $step["field_type"];

            // Combine Previous text if it is only a Descriptive
            $say_text                               = $descriptive_say_previous_step . $step["say_text"];
            $forwards[$field_name]["say_text"]   = $say_text;

            if($field_type == "descriptive" && $i !== $last_i){
                //IF THIS IS NOT THE LAST STEP IN THE SCRIPT, COMBINE IT WITH THE NEXT STEP , SO THE IVR WILL SAY BOTH 
                //BECAUSE EVERY STEP OF IVR SHOULD REQUIRE SOME INPUT
                $descriptive_say_previous_step = $say_text ." ";
                unset($forwards[$field_name]);
                $i++;
                continue;
            }

            $descriptive_say_previous_step  = "";
            $i++;
        }
        $script_dict = $forwards;
        if(!is_null($storage_key)){
            $this->setTempStorage($storage_key, "script_instrument", $this->script_instrument); 
            $this->setTempStorage($storage_key, "ivr_language", $this->ivr_language);
            $this->setTempStorage($storage_key, "ivr_voice", $this->ivr_voice);
            $this->setTempStorage($storage_key, "script", $script_dict);
            $this->setTempStorage($storage_key, "raw_script", $dict);
            $this->setTempStorage($storage_key, "branching", $branch_effectors);


            $first_step = current($script_dict);
            $this->setTempStorage($storage_key, "storage_key", $storage_key);
            $this->setTempStorage($storage_key, "last_step", $last_step["field_name"]);

            $this->setTempStorage($storage_key, "previous_step", null);
            $this->setTempStorage($storage_key, "current_step", $first_step["field_name"]);
        }

        return true;
    }

    /** 
     * FROM CURRENT STATE, PRODUCE THE CURRENT STEP OF THE SCRIPT
     * @return array
     */
    public function getCurrentIVRstep($response, $call_vars){
        $this->emDebug("HANDLE CURRENT STEP", $call_vars["previous_step"], $call_vars["current_step"], $call_vars["next_step"]);

        $this_step      = $call_vars["current_step"];
        $current_step   = $call_vars["script"][$this_step];
        $say_text       = $current_step["say_text"];

        // SPLIT UP "say" text into discreet say blocks by line break 
        // parse any special {{instructions}} 
        // say each line with a .5 second pause in between
        $say_arr        = array();
        $temp_say       = explode(PHP_EOL, $say_text );
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

        $presets        = $current_step["preset_choices"];
        $voicemail      = $current_step["voicemail"];
        $expected_digs  = $current_step["expected_digits"];
        $branching      = $current_step["branching"];
        $next_step      = $current_step["next_step"];
        
        //may need to repeat
        if(array_key_exists("repeat" , $call_vars)){
            // $say_text = "The previous input was unexpected. Please try again. " . $say_text;
            array_unshift($say_arr, array("say" => "The previous input was unexpected. Please try again.") );
        }

        if( ($branching && empty($voicemail)) || empty($next_step) ){
            //IF BRANCHING OR if next_step is empty, ITS NOT CERTAIN THAT THE NEXT SEQUENTIAL ARRAY ELEMENT IS CORRECT, 
            //SO START FROM THIS STEP AND ITERATE FORWARDTO THE NEXT NON has_branching STEP
            $mark = false;
            foreach($call_vars["script"] as $step_name => $step){
                if($step_name == $this_step){
                    $mark = true;
                    continue;
                }             

                if($mark && !$step["branching"]){
                    $next_step = $step["field_name"];
                    break;
                }
            }
            // $this->emDebug("if this step was the result of branching, sequential step flow may be broken, so need to iterate to find next clean step (no branching)", $next_step);
        }
        
        $speaker        = $call_vars["ivr_voice"];
        $accent         = $call_vars["ivr_language"];
        $voicelang_opts = array('voice' => $speaker, 'language' => $accent);
        
        $gather_options = array('numDigits' => $expected_digs);
        
        if($expected_digs > 1){
            $gather_options["finishOnKey"] = "#";
            // $say_text = $say_text . " Followed by the pound sign.";
            array_push($say_arr, array("say" => "Followed by the pound sign.") );
        }
        if(!empty($voicemail)){
            $gather_options["finishOnKey"] = "#";
            // $say_text = $say_text . " When you are finished recording press the pound sign.";
            array_push($say_arr, array("say" => "When you are finished recording press the pound sign.") );
        }

        //PAUSE TO BREATHE
        $response->pause(['length' => 1]);

        //SET UP GATHER , EVERY STEP MUST END IN gather
        $gather = $response->gather($gather_options); 

        // SAY EVERYTHING IN THE SAY BLOCK FIRST (OR DIAL OR PAUSE)
        // $this->emDebug("say array", $say_arr);

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
        }else{
            // $gather = $response->gather($gather_options); 
            // $gather->say($say_text, $voicelang_opts);   

            // Use <play> to play mp3
	        // $gather->play($module->getAssetUrl("v_languageselect.mp3"));
        }
        
        //3 RESET CALL VARS FOR NEXT IVR STEP
        $storage_key = $call_vars["storage_key"];
        $this->setTempStorage($storage_key, "previous_step", $this_step);
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