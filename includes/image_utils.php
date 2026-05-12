<?php
/**
 * REDLINER — Image Optimization Utility
 * Handles resizing and compression to keep listing pages fast.
 */

/**
 * Compresses and resizes an image to a specific width while maintaining aspect ratio.
 * 
 * @param string $source Path to source image
 * @param string $destination Path to save compressed image
 * @param int $maxWidth Max width in pixels (default 1200)
 * @param int $quality Compression quality (0-100, default 80)
 * @return bool Success
 */
function compressAndResizeImage($source, $destination, $maxWidth = 1200, $quality = 80) {
    $info = getimagesize($source);
    if (!$info) return false;

    $mime = $info['mime'];
    $width = $info[0];
    $height = $info[1];

    // Calculate new dimensions
    if ($width > $maxWidth) {
        $newWidth = $maxWidth;
        $newHeight = ($height / $width) * $newWidth;
    } else {
        $newWidth = $width;
        $newHeight = $height;
    }

    // Create image from source
    switch ($mime) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($source);
            break;
        case 'image/png':
            $image = imagecreatefrompng($source);
            // Preserve transparency for PNG
            imagealphablending($image, false);
            imagesavealpha($image, true);
            break;
        case 'image/webp':
            $image = imagecreatefromwebp($source);
            break;
        default:
            return false;
    }

    // Create a new true color image for the resized version
    $newImage = imagecreatetruecolor($newWidth, $newHeight);

    // Handle transparency for PNG/WebP in the new canvas
    if ($mime == 'image/png' || $mime == 'image/webp') {
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
        $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
        imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
    }

    // Perform resize
    imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    // Save based on extension
    $extension = strtolower(pathinfo($destination, PATHINFO_EXTENSION));
    
    // Convert all to JPG for maximum compression/compatibility, or keep WebP if it is already WebP
    // For this project, we'll keep the original format but compress it.
    $success = false;
    switch ($mime) {
        case 'image/jpeg':
            $success = imagejpeg($newImage, $destination, $quality);
            break;
        case 'image/png':
            // PNG quality is 0-9 for compression level
            $pngQuality = 9 - floor($quality / 10); 
            $success = imagepng($newImage, $destination, $pngQuality);
            break;
        case 'image/webp':
            $success = imagewebp($newImage, $destination, $quality);
            break;
    }

    // Free memory
    imagedestroy($image);
    imagedestroy($newImage);

    return $success;
}
