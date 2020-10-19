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
	    if (defined(PROJECT_ID)) {
	        
        }
    }

    function redcap_every_page_top($project_id){
		// every page load
    }
    
    /** 
     * GET DESIGNATED SCRIPT PROJECT XML
     * @return bool survey url link
     */
    public function loadScript($storage_key=null){
        //GET EM SETTINGS
        $this->script_instrument    = $this->getProjectSetting("active_instrument");
        $this->ivr_language         = $this->getProjectSetting("ivr_language");
        $this->ivr_voice            = $this->getProjectSetting("ivr_voice");

        //GET THE DICTIONARY WITH SCRIPT
        $dict               = \REDCap::getDataDictionary(PROJECT_ID, "array");
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
            $field_note         = $field["field_note"];
            $branching_logic    = $field["branching_logic"];
            $annotation         = $field["field_annotation"];
            $end_with_voicemail = trim($annotation) == "@VOICEMAIL" ? true : false;

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
                $next_step = !$has_branching ? $next_step : null;
                $next_step = !$end_with_voicemail ? $next_step : $last_step["field_name"];

                $script_dict[$field_name]  = array(
                    "field_name"        => $field_name,
                    "field_type"        => $field_type,
                    "say_text"          => $field_label,
                    "preset_choices"    => $preset_choices,
                    "voicemail"         => $end_with_voicemail,
                    "expected_digits"   => $field_note,
                    "branching"         => $has_branching,
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
            $this->setTempStorage($storage_key, "branching", $branch_effectors);


            $first_step = current($script_dict);
            $this->setTempStorage($storage_key, "storage_key", $storage_key);
            $this->setTempStorage($storage_key, "last_step", $last_step["field_name"]);

            $this->setTempStorage($storage_key, "previous_step", null);
            $this->setTempStorage($storage_key, "current_step", $first_step["field_name"]);
        }

        return true;
    }

    public function getCurrentIVRstep($response, $call_vars){
        $this->emDebug("HANDLE CURRENT STEP", $call_vars["previous_step"], $call_vars["current_step"], $call_vars["next_step"]);
        
        $this_step      = $call_vars["current_step"];
        $current_step   = $call_vars["script"][$this_step];

        $say_text       = $current_step["say_text"];
        $presets        = $current_step["preset_choices"];
        $voicemail      = $current_step["voicemail"];
        $expected_digs  = $current_step["expected_digits"];
        $branching      = $current_step["branching"];
        $next_step      = $current_step["next_step"];
        
        //may need to repeat
        if(array_key_exists("repeat" , $call_vars)){
            $say_text = "The previous input was unexpected. Please try again. " . $say_text;
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
        if($expected_digs > 1 || $voicemail){
            $gather_options["finishOnKey"] = "#";
            $say_text = $say_text . " Followed by the pound sign.";
        }

        

        //PAUSE TO BREATHE
        $response->pause(['length' => 1]);

        //1 SET UP DIGITS REQUEST (EVERY STEP OF IVR MUST ASK FOR INPUT TO MOVE ON)
        //2 SAY OR PROMPT
        if(!empty($presets)){
            $response->say($say_text, $voicelang_opts);  

            $gather = $response->gather($gather_options); 
            foreach($presets as $digit =>  $value){
                $prompt = "For $value press $digit";
                $gather->say($prompt, $voicelang_opts );
            }
        }else if($voicemail){
            $response->say($say_text, $voicelang_opts); 
            $response->record(['timeout' => 10, 'maxLength' => 15, 'transcribe' => 'true', "finishOnKey" => "#"]);
        }else{
            $gather = $response->gather($gather_options); 
            $gather->say($say_text, $voicelang_opts);   

            // Use <play> to play mp3
	        // $gather->play($module->getAssetUrl("v_languageselect.mp3"));
        }

        
        //3 RESET CALL VARS FOR NEXT IVR STEP
        $storage_key = $call_vars["storage_key"];
        $this->setTempStorage($storage_key, "previous_step", $this_step);
        $this->setTempStorage($storage_key, "current_step", $next_step);
    }

    /**
     * Verifies the invitation access code and marks it as used, and creates a record in the main project with all the answers supplied via voice 
     * @return bool survey url link
     */
    public function IVRHandler($call_vars) {
        $data = array();
        $data["record_id"] = $this->getNextAvailableRecordId(PROJECT_ID);

        $script_fieldnames = \REDCap::getFieldNames($this->script_instrument);
        foreach($call_vars as $rc_var => $rc_val){
            if( !in_array($rc_var, $script_fieldnames) ){
                continue;
            }
            $data[$rc_var] = $rc_val;
        }

        $r    = \REDCap::saveData('json', json_encode(array($data)) );
        // $this->emDebug("DID IT REALLY SAVE IVR ???", $data, $r);
        
        return false;
    }



    /**
     * Set Temp Store Proj Settings
     * @param $key $val pare
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
     * @param $key $val pare
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
     * @param $key $val pare
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
    function getAssetUrl($audiofile = "v_languageselect.mp3", $hard_domain = ""){
        $audio_file = $this->framework->getUrl("getAsset.php?file=".$audiofile."&ts=". $this->getLastModified() , true, true);
        
        if(!empty($hard_domain)){
            $audio_file = str_replace("http://localhost",$hard_domain, $audio_file);
        }

        $this->emDebug("The NO AUTH URL FOR AUDIO FILE", $audio_file); 
        return $audio_file;
    }
    
    /**
     * Return an error
     * @param $msg
     */
    public function returnError($msg) {
        $this->emDebug($msg);
        header("Content-type: application/json");
        echo json_encode(array("error" => $msg));
        exit();
    }

    /*
        USE mail func
    */
    public function sendEmail($subject, $msg, $from="Twilio VM", $to="ca-factstudy@stanford.edu"){
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

    
    /* 
        Parse CSV to batchupload test Results
    */
    public function parseCSVtoDB($file){

        $header_row = true;
        $file       = fopen($file['tmp_name'], 'r');

        $headers    = array();
        $results    = Array();

        //now we parse the CSV, and match the QR -> UPC 
        if($file){
            while (($line = fgetcsv($file)) !== FALSE) {
                if($header_row){
                    // adding extra column to determine which file the data came from
                    $headers 	= $line;
                    $header_row = false;
                }else{
                    // adding extra column to determine which csv file the data came from
                    array_push($results, $line);
                }
            }
            fclose($file);
        }
        
        $header_count   = count($headers);
        $data           = array();
        foreach($results as $rowidx => $result){
            $qrscan     = $result[0];
            $upcscan    = $result[1];
            // $api_result = $this->getKitSubmissionId($qrscan);
            // $this->emDebug("now what", $api_result);
            // if(isset($api_result["participant_id"])){
            //     $record_id      = $api_result["record_id"];
            //     $records        = $api_result["all_matches"];
            //     $mainid         = $api_result["main_id"];

            //     foreach($records as $result){
            //         // SAVE TO REDCAP
            //         $temp   = array(
            //             "record_id"         => $result["record_id"],
            //             "kit_upc_code"      => $upcscan,
            //             "kit_qr_input"      => $qrscan,
            //             "household_record_id" => $mainid
            //         );
            //         $data[] = $temp;
            //         $r  = \REDCap::saveData('json', json_encode(array($temp)) );
            //         if(!empty($r["errors"])){
            //             $this->emDebug("save to kit_submit, the UPC and main record_id", $rowidx, $r["errors"]);
            //         }
            //     }
            // }else{
            //     $this->emDebug("No API results for qrscan for row $rowidx");
            // }
        }        
                
        return $r;
    }
}
?>