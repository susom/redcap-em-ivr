# Stand Alone REDCap + Twilio Interactive Voice Response

This EM works in conjunction with its Associated Project to manage an Interactive Voice Response script tree via Twilio.

## Basic Instructions

* Use dedicated instrument in the project to be the script (and then set in EM setting)
* Script should flow TOP to BOTTOM
* primary record field must be **[record_id]**
* **ONLY** [descriptive], [text], [radio/truefalse/yesno], [dropdown] fields may be used... NO ~~[checkbox]~~
* Use [descriptive] fields for speaking text only
* Use [field_label] fields for speaking text that immedietely precedes input prompts
* Script **MUST** end with a [descriptive] field to end the call eg. "Thank you , Good bye"
* [field_note] fields pass in meta data and **MUST** be JSON FORMAT
* {{Special Instructions Syntax}} can be used within speaking text and will be parsed and acted on.  Unrecognized {{instructions}} will be removed and ignored.
* Script will honor any valid redcap branching logic
* when [expected_digits] > 1 the phrase "Followed by the 'pound' sign." will be automatically inserted after the [field_label] prompt text, plan accordingly
* when using [radio/truefalse/yesno/dropdown] fields with preset number/value options.  The caller will be prompted in the following manner "For [value] press [number]" in sequence.   eg. "For Cats press 1 ,  For Dogs press 2"
* Each IVR Project needs these two specific variables somewhere in them **[caller_phone_number] (with -NONE- for input validation) and [vm_transcription]** with **"@IGNORE"** annotations
* voicemail/recording transcriptions if any will be posted back into the field var [vm_transcription]

## Reference

### Currently Recognized {{Special Instructions}}
* {{PAUSE=n}} : input an n second pause eg. {{PAUSE=3}} or {{PAUSE=.5}} for a 3 second and a half second pause respectively
* {{CALLFORWARD=xyz}} : Forward current call to 10 digit phone number (caller id will show call coming from your phone) eg. {{CALLFORWARD=4155551234}}

### Currently Recognized field_note JSON
* {"expected_digits" : n} : this step of the script expects n number of input digits eg. {"expected_digits" : 10} when asking for a 10 digit phone number
* {"voicemail" : {"timeout" : x, "length" : y}} : This will prompt the caller to leave a voice recording with either an x number of seconds to record or a y number of seconds length recording.  This will immedietely jump the entire script to the last step after prompting for a recording and end the call.



