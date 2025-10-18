<?php
// Image proxy script to serve medicine images
if (!isset($_GET['path']) || empty($_GET['path'])) {
    http_response_code(404);
    exit('Image not found');
}

$image_path = $_GET['path'];
$full_path = __DIR__ . '/../uploads/' . $image_path;

// Security check - only allow images from uploads folder
if (!file_exists($full_path) || strpos($image_path, '..') !== false) {
    http_response_code(404);
    exit('Image not found');
}

// Get file info
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $full_path);
finfo_close($finfo);

// Set appropriate headers
header('Content-Type: ' . $mime_type);
header('Content-Length: ' . filesize($full_path));
header('Cache-Control: public, max-age=3600'); // Cache for 1 hour

// Output the image
readfile($full_path);
?>
