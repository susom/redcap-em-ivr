<?php
namespace Stanford\IVRStandAlone;
/** @var \Stanford\IVRStandAlone\IVRStandAlone $module */

// Cache Time in Seconds
$day_cache          = 86400;
$week_cache         = 604800;
$month_cache        = 2628000;
$year_cache         = 31536000;

// GET FILE NAME OF DESIRED FILE FOR PASS THRU
$content_type       = isset($_GET['mime_type']) ? filter_var($_GET['mime_type'], FILTER_SANITIZE_STRING) : null;
$doc_temp_path      = isset($_GET['doc_temp_path']) ? filter_var($_GET['doc_temp_path'], FILTER_SANITIZE_STRING) : null;

// now serve the file, if path is real, and file exists
if( file_exists($doc_temp_path) ){
    //Path is okay
    ob_start(); // collect all outputs in a buffer

    set_time_limit(0);
    $fh = fopen($doc_temp_path, "rb");
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
    header('Content-Length: ' . sprintf("%u", filesize($doc_temp_path)));

    header_remove("Pragma");
    header_remove("Expires");
    echo $sContent;
} else {
   //User wants to gain access into a forbidden area.
   header('HTTP/1.1 404 Not Found');
}

// $module->emDebug("Inside getEdocAsset.php:");
exit();
?>