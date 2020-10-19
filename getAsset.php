<?php
namespace Stanford\IVRStandAlone;
/** @var \Stanford\IVRStandAlone\IVRStandAlone $module */

// Cache Time in Seconds
$day_cache          = 86400;
$week_cache         = 604800;
$month_cache        = 2628000;
$year_cache         = 31536000;

// GET FILE NAME OF DESIRED FILE FOR PASS THRU
$get_file           = isset($_GET['file']) ? filter_var($_GET['file'], FILTER_SANITIZE_STRING) : null;
$file               = $get_file ? $get_file : "v_languageselect.mp3";
$file               = preg_replace("/.*\/|\.{2,}/", "", $file);
$extension          = strtolower( pathinfo($file, PATHINFO_EXTENSION) );

// SETTING SUB FOLDERS TO RESTRICT FOLDER TO PICK FILES FROM
$content_type       = "text/plain";
$subfolder          = "/docs/audio/";
$cache_age          = $year_cache; // Default 1 year

// SET CONTENT-TYPE
$bad_ext = false;
if($extension ==  "mp3"){
    $content_type   = "audio/mpeg";
    $subfolder      = "/docs/audio/";
}else{
    $bad_ext = true;
}

// GET ABS PATH TO FILE
/* 
double layer of protection against directory traversal
first : remove the damn ../
second : check the absolute (realpath) 

The path provided by the user is sent to the storage path. Then realpath is used to convert this path to an absolute path. If the absolute path begins with the storage path everything is okay, otherwise not.
*/

$storagePath        = dirname(__FILE__);
$filepath           = $storagePath . $subfolder . $file;
$checkpath          = realpath($filepath);

// now serve the file, if path is real, and file exists
if( strpos($checkpath, $storagePath."/docs") === 0 && file_exists($filepath) && !$bad_ext){
    //Path is okay
    ob_start(); // collect all outputs in a buffer

    // USE MODIFIED TIME AS ETag, RATHER than OPENING WHOLE FILE AND HASHING THAT
    $modified_time  = filemtime($filepath);

    set_time_limit(0);
    $fh = fopen($filepath, "rb");
    while (!feof($fh)) {
        echo fgets($fh);
    }
    fclose($fh);
    $sContent = ob_get_contents(); // collect all outputs in a variable
    ob_clean();

    // It is important to specify  Cache-Control max-age, and ETag, for all cacheable resources.
    // Set download headers MUST BE IN THIS ORDER
    header('HTTP/1.1 200 OK', true, 200);
    header('Cache-Control: max-age='.$cache_age.', public');
    header('Content-Type:  ' . $content_type);
    // header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . sprintf("%u", filesize($filepath)));

    header_remove("Pragma");
    header_remove("Expires");
    echo $sContent;
} else {
   //User wants to gain access into a forbidden area.
   header('HTTP/1.1 404 Not Found');
}

$module->emDebug("Inside getAsset.php:");
exit();
?>