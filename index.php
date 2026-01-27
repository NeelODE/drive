<?php
// --- CONFIGURATION ---
// !! IMPORTANT !! Set this to the *server path* of your storage folder
// Make sure this path exists and the web server (PHP process) has read/write permissions.
$base_dir = realpath('my_files'); // Use realpath for basic security

// --- SECURITY CHECK ---
if (!$base_dir) {
    // Try to provide a more helpful error if possible
    $attempted_path = '/home/user/public_html/my_files'; // Example: Hardcode the attempted path for the error message
    http_response_code(500); // Internal Server Error
    die("Configuration error: Base directory '$attempted_path' not found, inaccessible, or `realpath()` failed. Check the path and permissions.");
}
// Optional: Set higher time limit for potentially long copies/moves, if allowed by host
// @set_time_limit(300); // 5 minutes (might not work on free hosting)

// --- CONSTANTS ---
define('MAX_TEXT_VIEW_SIZE', 5 * 1024 * 1024); // 5 MB limit for viewing text files in modal

// --- HELPER FUNCTIONS ---

/** 
 * Sanitizes a user-provided path relative to a base directory.
 * Prevents path traversal (../), null bytes, and other issues.
 * Ensures the final resolved path is within the base directory.
 *
 * @param string $path User-provided relative path.
 * @param string $base The absolute path to the base directory.
 * @return string|false The sanitized absolute path within the base, or false on failure/security violation.
 */
function sanitize_path($path, $base) {
    // 1. Normalize separators and trim whitespace/slashes
    $path = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR . " \t\n\r\0\x0B");

    // 2. Prevent null byte injection early
    if (strpos($path, "\0") !== false) {
        return false;
    }

    // 3. Handle empty path or dots (means the base directory itself)
    if (empty($path) || $path === '.') {
        return rtrim($base, DIRECTORY_SEPARATOR);
    }
    // Prevent accessing parent directly
    if ($path === '..') {
        return false;
    }

    // 4. Prevent accessing hidden files/folders (optional, uncomment for more security)
    /*
    if (preg_match('/(^|\/)\.[^\/.]/', $path)) { // Checks for /. or starting with .
        return false;
    }
    */

    // 5. Manual normalization to detect tricky traversals ('../', '/./') BEFORE realpath
    $base_parts = explode(DIRECTORY_SEPARATOR, trim($base, DIRECTORY_SEPARATOR));
    $path_parts = explode(DIRECTORY_SEPARATOR, $path);
    $absolute_parts = $base_parts;

    foreach ($path_parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            // Check if we are trying to go above the base directory during normalization
            if (count($absolute_parts) <= count($base_parts)) {
                return false; // Traversal attempt detected during normalization
            }
            array_pop($absolute_parts);
        } else {
            $absolute_parts[] = $part;
        }
    }

    // Reconstruct the path
    $normalized_path = implode(DIRECTORY_SEPARATOR, $absolute_parts);
    // Add root slash for non-Windows systems if needed
    if (DIRECTORY_SEPARATOR === '/' && !empty($normalized_path)) {
        $normalized_path = DIRECTORY_SEPARATOR . $normalized_path;
    }
    // Handle Windows drive letters (simple case)
     if (DIRECTORY_SEPARATOR !== '/' && isset($base_parts[0]) && strpos($base_parts[0], ':') === 1) {
         // Ensure the drive letter from base_parts is preserved if needed
         // This part might need refinement depending on Windows path complexity
         if (!isset($absolute_parts[0]) || strpos($absolute_parts[0], ':') !== 1) {
             // If drive letter was lost somehow, prepend it (basic assumption)
             // A more robust solution might be needed for complex Windows paths
             $normalized_path = $base_parts[0] . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, array_slice($absolute_parts, 1));
         } else {
             $normalized_path = implode(DIRECTORY_SEPARATOR, $absolute_parts);
         }
     }


    // 6. Use realpath() to resolve symlinks and canonicalize the path
    // Important: Use the *manually normalized* path for realpath, not the direct user input concatenation
    $realpath = realpath($normalized_path);

    // 7. Final Security Check: Ensure the resolved path exists and is within the base directory.
    $base_realpath = rtrim(realpath($base), DIRECTORY_SEPARATOR); // Resolve base dir too
    $base_realpath_with_sep = $base_realpath . DIRECTORY_SEPARATOR;

    if ($realpath === false) {
        // If realpath fails, it might be because the *target* doesn't exist yet (e.g., create folder/upload).
        // In this case, check if the *parent* exists and is within the base.
        $parent_normalized = dirname($normalized_path);
        $parent_realpath = realpath($parent_normalized);
        if ($parent_realpath !== false && ($parent_realpath === $base_realpath || strpos($parent_realpath, $base_realpath_with_sep) === 0)) {
            // Parent is valid, return the *normalized* path (not the failed realpath)
            // This allows creating new items in valid locations.
            return $normalized_path;
        }
        return false; // Parent doesn't exist or is outside base
    }

    // Check if the resolved path is the base directory itself or starts with the base directory path + separator
    if ($realpath === $base_realpath || strpos($realpath, $base_realpath_with_sep) === 0) {
        return $realpath; // Path is valid and within the base directory
    }

    // Log potential security issue if path resolution leads outside base
    error_log("Security Alert: Sanitized path '$path' resolved to '$realpath' which is outside base '$base_realpath'");
    return false; // Outside base directory
}


/**
 * Gets the relative path of a full path with respect to a base directory.
 *
 * @param string $full_path The absolute path.
 * @param string $base The absolute path of the base directory.
 * @return string|false The relative path (or empty string for base itself), or false if not within base.
 */
function get_relative_path($full_path, $base) {
    $base = rtrim(realpath($base), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $full_path = rtrim(realpath($full_path), DIRECTORY_SEPARATOR);
    $base_norm = rtrim($base, DIRECTORY_SEPARATOR);

    // Handle case where full_path is the base directory itself
    if ($full_path === $base_norm) {
        return '';
    }
    // Check if the full path starts with the base path + separator
    if (strpos($full_path, $base) === 0) {
        // Return the part after the base path, trimming separators
        return trim(substr($full_path, strlen($base)), DIRECTORY_SEPARATOR);
    }
    return false; // Not within the base directory
}

/**
 * Formats bytes into a human-readable string (KB, MB, GB...). PHP version.
 *
 * @param int|float|null $bytes Number of bytes.
 * @param int $precision Number of decimal places.
 * @return string Formatted string or empty string on error.
 */
function format_bytes_php($bytes, $precision = 2) {
    if ($bytes === null || $bytes < 0 || !is_numeric($bytes)) return '';
    if ($bytes == 0) return '0 Bytes';
    $base = log($bytes, 1024);
    $suffixes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
    $index = floor($base);
    $index = min($index, count($suffixes) - 1); // Prevent index out of bounds
    return round(pow(1024, $base - $index), $precision) . ' ' . $suffixes[$index];
}

/**
 * Recursively copies a directory or file.
 *
 * @param string $source Source path.
 * @param string $dest Destination path.
 * @return bool True on success, false on failure.
 */
function recursive_copy($source, $dest) {
    $source = rtrim($source, DIRECTORY_SEPARATOR);
    $dest = rtrim($dest, DIRECTORY_SEPARATOR);

    // Simple file copy
    if (!is_dir($source)) {
        if (!is_readable($source)) { error_log("Copy failed: Source file not readable: $source"); return false; }
        $dest_dir = dirname($dest);
        if (!is_dir($dest_dir)) {
            if (!@mkdir($dest_dir, 0755, true)) { error_log("Copy failed: Cannot create destination directory: $dest_dir"); return false; }
        }
        if (!is_writable($dest_dir)) { error_log("Copy failed: Destination directory not writable: $dest_dir"); return false; }
        return @copy($source, $dest);
    }

    // Directory copy
    if (!is_dir($dest)) {
        if (!@mkdir($dest, 0755, true)) { error_log("Copy failed: Cannot create destination directory: $dest"); return false; }
    }
    if (!is_writable($dest)) { error_log("Copy failed: Destination directory not writable: $dest"); return false; }

    $success = true;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
             if (!$item->isReadable()) {
                 error_log("Copy failed: Item not readable during recursion: " . $item->getPathname());
                 $success = false; break; // Stop if cannot read item
             }
            $destPath = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($destPath) && !@mkdir($destPath, 0755)) {
                    error_log("Copy failed: Cannot create sub-directory: $destPath");
                    $success = false; break;
                }
                 if (!is_writable($destPath)) { error_log("Copy failed: Sub-directory not writable: $destPath"); $success = false; break; }
            } else {
                if (!@copy($item->getPathname(), $destPath)) {
                     error_log("Copy failed: Cannot copy file: " . $item->getPathname() . " to " . $destPath);
                     $success = false; break;
                }
            }
        }
    } catch (Exception $e) {
        error_log("Recursive copy error: " . $e->getMessage());
        return false;
    }
    // If copy failed, attempt to clean up the partially created destination directory
    if (!$success && is_dir($dest)) {
        @recursive_delete($dest); // Attempt cleanup
    }
    return $success;
}

/**
 * Recursively deletes a directory and its contents. USE WITH EXTREME CAUTION.
 *
 * @param string $dir Directory path to delete.
 * @return bool True on success, false on partial or total failure.
 */
function recursive_delete($dir) {
    $dir = rtrim($dir, DIRECTORY_SEPARATOR);
    if (!is_dir($dir) || !is_readable($dir)) { // Check readability before iterating
        error_log("Recursive delete failed: Directory not found or not readable: $dir");
        return false;
    }
     // Check writability of the directory itself before starting
     if (!is_writable($dir)) {
         error_log("Recursive delete failed: Directory not writable (cannot delete items within): $dir");
         return false;
     }

    $success = true;
    try {
        // CHILD_FIRST ensures files/subdirs are deleted before their parent
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::UNIX_PATHS), // UNIX_PATHS for consistent separators
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
             // Double check writability of the item or its parent before attempting delete
             if (!is_writable($item->isDir() ? $item->getPathname() : $item->getPath())) {
                 error_log("Recursive delete skipped: Item or parent directory not writable: " . $item->getPathname());
                 $success = false; // Mark as partial failure, but continue trying to delete others
                 continue;
             }

            if ($item->isDir()) {
                if (!@rmdir($item->getRealPath())) {
                    error_log("Recursive delete failed: Could not remove directory: " . $item->getRealPath());
                    $success = false; // Mark as partial failure
                }
            } else {
                if (!@unlink($item->getRealPath())) {
                     error_log("Recursive delete failed: Could not remove file: " . $item->getRealPath());
                     $success = false; // Mark as partial failure
                }
            }
        }
        // Finally, try to remove the top-level directory itself
        if ($success && !@rmdir($dir)) {
            error_log("Recursive delete failed: Could not remove top-level directory: " . $dir);
            $success = false;
        }
    } catch (Exception $e) {
        error_log("Recursive delete exception: " . $e->getMessage());
        return false; // Major failure
    }
    return $success;
}

// --- Request Parsing ---
$action = $_REQUEST['action'] ?? 'list';
$current_dir_relative = trim($_REQUEST['dir'] ?? '', '/\\');

// --- Path Initialization and Sanitization ---
$current_dir_full = sanitize_path($current_dir_relative, $base_dir);

if ($current_dir_full === false) {
    // If sanitization fails (e.g., '..', invalid chars, outside base), default safely to base directory.
    // Log this occurrence.
    error_log("Path sanitization failed for relative path '$current_dir_relative'. Defaulting to base directory.");
    $current_dir_full = realpath($base_dir); // Use realpath on base just to be sure
    $current_dir_relative = '';
} else {
    // Ensure relative path corresponds to the *sanitized* full path
    $current_dir_relative = get_relative_path($current_dir_full, $base_dir);
    // If get_relative_path fails after successful sanitize_path, something is wrong. Default to base.
    if ($current_dir_relative === false) {
        error_log("Failed to get relative path for valid sanitized path '$current_dir_full'. Defaulting to base.");
        $current_dir_full = realpath($base_dir);
        $current_dir_relative = '';
    }
}

// --- Direct Media Serving (for action=view&inline=1) ---
// IMPORTANT: Must run *before* any AJAX/POST handling or HTML output.
if ($action === 'view' && isset($_GET['inline']) && $_GET['inline'] === '1') {
    $file_to_serve_relative = trim($_GET['path'] ?? '');

    // Sanitize path for serving
    $file_path_full = sanitize_path($file_to_serve_relative, $base_dir);

    // Validation & Security
    if ($file_path_full === false || !is_file($file_path_full) ) {
         http_response_code(404); die("Error: File not found or is not a file.");
    }
     if (!is_readable($file_path_full)) {
          http_response_code(403); die("Error: File not readable.");
     }
     // No need for extra base dir check here, sanitize_path guarantees it if it returns a path.

    // Determine MIME Type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = $finfo ? finfo_file($finfo, $file_path_full) : 'application/octet-stream';
    finfo_close($finfo);

     // Check if it's a type suitable for inline display
     $allowed_inline_prefixes = ['image/', 'video/', 'audio/'];
     $allowed_inline_exact = ['application/pdf'];
     $is_inline_type = false;
     foreach ($allowed_inline_prefixes as $prefix) { if (strpos($mime_type, $prefix) === 0) { $is_inline_type = true; break; } }
     if (!$is_inline_type && in_array($mime_type, $allowed_inline_exact)) { $is_inline_type = true; }

     if (!$is_inline_type) {
         http_response_code(415); die("File type ($mime_type) cannot be viewed inline.");
     }

    $filesize = @filesize($file_path_full);
    $filename = basename($file_path_full);

    // Set headers for inline display
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"'); // Raw URL encode filename for header safety
    header('Accept-Ranges: bytes'); // Indicate support for range requests (good for video/audio seeking)
    header('Cache-Control: public, max-age=600'); // Allow some caching
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 600) . ' GMT');
    header('Pragma: public'); // For older clients/proxies

    // Handle Range requests (for seeking in video/audio)
    $start = 0;
    $end = $filesize - 1;
    if (isset($_SERVER['HTTP_RANGE'])) {
        $range = $_SERVER['HTTP_RANGE'];
        if (preg_match('/bytes=(\d+)-(\d+)?/', $range, $matches)) {
            $start = intval($matches[1]);
            if (isset($matches[2])) {
                $end = intval($matches[2]);
            }
            // Ensure range is valid
            $end = min($end, $filesize - 1);
            if ($start > $end || $start >= $filesize) {
                http_response_code(416); // Range Not Satisfiable
                header("Content-Range: bytes */$filesize");
                exit;
            }
            http_response_code(206); // Partial Content
            header("Content-Range: bytes $start-$end/$filesize");
            header('Content-Length: ' . ($end - $start + 1));
        } else {
            // Invalid range format, ignore it and send full file
             header('Content-Length: ' . $filesize);
        }
    } else {
        // No range requested, send full file
         if ($filesize !== false) header('Content-Length: ' . $filesize);
    }

    // Clean output buffer
    ob_clean();
    flush(); // Flush system output buffer

    // Read and output the file content (potentially partial)
    $handle = @fopen($file_path_full, 'rb');
    if ($handle) {
        fseek($handle, $start);
        $bytes_to_read = ($end - $start + 1);
        while ($bytes_to_read > 0 && !feof($handle)) {
            $chunk_size = min(8192, $bytes_to_read); // Read in chunks
            echo fread($handle, $chunk_size);
            $bytes_to_read -= $chunk_size;
            flush(); // Flush output buffer periodically for large files
        }
        fclose($handle);
    } else {
        error_log("Inline view error: Failed to open file for reading: '$file_path_full'");
        // Headers already sent, difficult to report error cleanly
    }
    exit; // Stop script execution after serving the file
}


// --- HANDLE AJAX/POST REQUESTS ---
// Check if it's an AJAX request (XMLHttpRequest header) or POST, or has ?ajax=1 query param
if (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    $_SERVER['REQUEST_METHOD'] === 'POST' ||
    isset($_GET['ajax'])
) {
    // Prevent caching of AJAX responses
    header('Cache-Control: no-cache, must-revalidate, max-age=0');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    header('Pragma: no-cache');
    // Set default JSON header, might be overridden by specific actions (like view)
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }

    $response = ['success' => false, 'message' => 'An error occurred.'];
    $http_status_code = 500; // Default error code

    try {
        // --- Permission Checks (Do early for relevant actions) ---
        if (in_array($action, ['upload', 'create_folder', 'paste', 'delete'])) {
             // Write permission needed in the *current* directory for upload, create_folder
             if (in_array($action, ['upload', 'create_folder'])) {
                  if (!is_writable($current_dir_full)) {
                       $http_status_code = 403; throw new Exception("Permission denied: Directory '$current_dir_relative/' is not writable.");
                  }
             }
             // For paste, need write permission in destination ($current_dir_full)
             if ($action === 'paste') {
                 if (!is_writable($current_dir_full)) {
                       $http_status_code = 403; throw new Exception("Permission denied: Destination directory '$current_dir_relative/' is not writable.");
                  }
                  // Read permission needed for source item (checked later after getting source path)
             }
             // For delete, need write permission in the *parent* of the item being deleted (checked later)
        }
        // Read permission for list/view/download/copy-source is checked when accessing the item.

        // --- Action Handler ---
        switch ($action) {
            case 'list':
                $files = [];
                $items = @scandir($current_dir_full);
                if ($items === false) {
                    $http_status_code = 404; // Or 403 if permissions issue
                    throw new Exception("Could not read directory: " . ($current_dir_relative ?: '/'));
                }

                 // Add '..' navigation link if not in the base directory
                 if ($current_dir_full !== realpath($base_dir)) {
                     $parent_path_full = dirname($current_dir_full);
                     // Sanitize parent path to ensure it's still within base
                     $parent_path_sanitized = sanitize_path(get_relative_path($parent_path_full, $base_dir) ?: '', $base_dir);
                     if ($parent_path_sanitized !== false) { // Ensure parent is valid and within base
                         $parent_relative_path = get_relative_path($parent_path_sanitized, $base_dir);
                         if ($parent_relative_path !== false) { // Should succeed if sanitized path is valid
                             $files[] = [
                                 'name' => '..',
                                 'path' => $parent_relative_path,
                                 'is_dir' => true, 'size' => null,
                                 'modified' => @filemtime($parent_path_sanitized) ?: time(),
                                 'is_back' => true
                             ];
                         }
                     }
                 }

                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') continue;
                    // Optional: Skip hidden files/folders based on name
                    // if (substr($item, 0, 1) === '.') continue;

                    $item_path_full = $current_dir_full . DIRECTORY_SEPARATOR . $item;
                    // Use is_readable as a basic check before getting more info
                    if (!is_readable($item_path_full)) {
                         error_log("Skipping unreadable item: $item_path_full");
                         continue; // Skip items the server can't read
                    }
                    $is_dir = is_dir($item_path_full);
                    $item_relative_path = get_relative_path($item_path_full, $base_dir);
                    if ($item_relative_path === false) continue; // Should not happen if listing is correct

                    $file_size = null;
                    if (!$is_dir) {
                         try { $file_size = @filesize($item_path_full); } catch (ErrorException $e) { $file_size = -1; }
                         if ($file_size === false) $file_size = -1; // Indicate error or unknown size
                    }

                    $files[] = [
                        'name' => $item,
                        'path' => $item_relative_path,
                        'is_dir' => $is_dir,
                        'size' => $file_size,
                        'modified' => @filemtime($item_path_full) ?: time(),
                        'is_back' => false
                    ];
                }
                // Sort: '..' first, then dirs, then files, all alphabetically
                 usort($files, function ($a, $b) {
                     if ($a['is_back']) return -1; if ($b['is_back']) return 1;
                     if ($a['is_dir'] !== $b['is_dir']) { return $b['is_dir'] <=> $a['is_dir']; } // Dirs first
                     return strnatcasecmp($a['name'], $b['name']); // Natural case-insensitive sort
                 });

                $response = ['success' => true, 'files' => $files, 'current_dir' => $current_dir_relative];
                $http_status_code = 200;
                break;

            case 'upload':
                if (!isset($_FILES['fileToUpload'])) {
                    $http_status_code = 400; throw new Exception('No file data received.');
                }
                $file = $_FILES['fileToUpload'];

                // Check for upload errors
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $upload_errors = [
                        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit (upload_max_filesize).',
                        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit (MAX_FILE_SIZE).',
                        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                        UPLOAD_ERR_NO_FILE    => 'No file was selected for upload.',
                        UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error: Missing temporary folder.',
                        UPLOAD_ERR_CANT_WRITE => 'Server error: Failed to write file to disk.',
                        UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
                    ];
                    $http_status_code = 400; throw new Exception($upload_errors[$file['error']] ?? 'Unknown upload error occurred.');
                }

                // Sanitize filename
                $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', basename($file['name'])); // Remove control chars
                $filename = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $filename); // Replace reserved chars
                $filename = trim($filename);
                if (empty($filename) || $filename === '.' || $filename === '..') {
                     $http_status_code = 400; throw new Exception('Invalid or empty filename provided.');
                }

                // Construct target path and sanitize it AGAIN to be absolutely sure
                $target_relative_path = ($current_dir_relative ? $current_dir_relative . DIRECTORY_SEPARATOR : '') . $filename;
                $target_path_full = sanitize_path($target_relative_path, $base_dir);

                // Security check: Ensure sanitized target is still within the intended directory
                if ($target_path_full === false || dirname($target_path_full) !== $current_dir_full) {
                     $http_status_code = 400; throw new Exception('Upload path security check failed after filename sanitization.');
                }

                 if (file_exists($target_path_full)) {
                    $http_status_code = 409; // Conflict
                    throw new Exception("File '{$filename}' already exists in this directory. Cannot overwrite.");
                 }

                // Use move_uploaded_file() - includes security checks
                if (@move_uploaded_file($file['tmp_name'], $target_path_full)) {
                    @chmod($target_path_full, 0644); // Set reasonable permissions if possible
                    $response = ['success' => true, 'message' => "File '{$filename}' uploaded successfully."];
                    $http_status_code = 201; // Created
                } else {
                    // Try to determine why it failed
                    $last_error = error_get_last();
                    $error_message = "Failed to move uploaded file '$filename'.";
                    if (!is_writable($current_dir_full)) { // Re-check writability just before error
                         $error_message .= ' Target directory is not writable.'; $http_status_code = 403;
                    } elseif ($last_error && stripos($last_error['message'], 'permission') !== false) {
                        $error_message .= ' Check server permissions.'; $http_status_code = 403;
                    } elseif (!file_exists($file['tmp_name']) && $file['error'] === UPLOAD_ERR_OK) {
                       $error_message .= ' Temporary file disappeared (check disk space or config).'; $http_status_code = 500;
                    } else {
                         $error_message .= ' Unknown server error occurred.'; $http_status_code = 500;
                    }
                    throw new Exception($error_message);
                }
                break;

            case 'create_folder':
                $folder_name = trim($_POST['folder_name'] ?? '');
                 // Sanitize folder name aggressively
                $folder_name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename($folder_name));
                $folder_name = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $folder_name);
                $folder_name = trim($folder_name);

                if (empty($folder_name) || $folder_name === '.' || $folder_name === '..') {
                     $http_status_code = 400; throw new Exception('Invalid or empty folder name provided.');
                }

                // Construct target path and sanitize
                $new_folder_relative_path = ($current_dir_relative ? $current_dir_relative . DIRECTORY_SEPARATOR : '') . $folder_name;
                $new_folder_path_full = sanitize_path($new_folder_relative_path, $base_dir);

                 // Security check: Ensure sanitized target is still within the intended directory
                if ($new_folder_path_full === false || dirname($new_folder_path_full) !== $current_dir_full) {
                     $http_status_code = 400; throw new Exception('Create folder path security check failed.');
                }

                if (file_exists($new_folder_path_full)) {
                    $http_status_code = 409; // Conflict
                    throw new Exception("An item named '{$folder_name}' already exists in this directory.");
                }

                if (@mkdir($new_folder_path_full, 0755)) { // Use appropriate permissions
                    $response = ['success' => true, 'message' => "Folder '{$folder_name}' created successfully."];
                    $http_status_code = 201; // Created
                } else {
                     $last_error = error_get_last();
                     $error_message = "Failed to create folder '{$folder_name}'.";
                      if (!is_writable($current_dir_full)) { // Re-check writability
                           $error_message .= ' Parent directory is not writable.'; $http_status_code = 403;
                      } elseif ($last_error && stripos($last_error['message'], 'permission') !== false) {
                           $error_message .= ' Check parent directory permissions.'; $http_status_code = 403;
                      } else {
                           $error_message .= ' Unknown server error.'; $http_status_code = 500;
                      }
                    throw new Exception($error_message);
                }
                break;

             case 'delete':
                 $item_relative_path = trim($_POST['path'] ?? '');
                 if (empty($item_relative_path) && $item_relative_path !== '0') { // Allow '0' maybe? Check sanitize_path logic.
                     $http_status_code = 400; throw new Exception('No item path specified for deletion.');
                 }

                 $item_path_full = sanitize_path($item_relative_path, $base_dir);

                 // Cannot delete base dir itself or if path is invalid
                 if ($item_path_full === false || $item_path_full === realpath($base_dir)) {
                    $http_status_code = 400; throw new Exception('Cannot delete this item (invalid path or base directory).');
                 }
                 // Security check already handled by sanitize_path ensuring it's within base.

                 if (!file_exists($item_path_full)) {
                     // Item might have been deleted already. Treat as success.
                     $response = ['success' => true, 'message' => 'Item already deleted or not found.'];
                     $http_status_code = 200;
                     break; // Exit switch
                 }

                 // Check permissions before attempting delete
                 $parent_dir = dirname($item_path_full);
                 if (!is_writable($parent_dir)) {
                     $http_status_code = 403; throw new Exception("Permission denied: Cannot delete item, parent directory '$parent_dir' is not writable.");
                 }
                 // Also check if the item itself needs to be writable (might matter on some systems, e.g., sticky bit)
                 if (!is_writable($item_path_full)) {
                     // Log a warning, but attempt deletion anyway, as parent writability is usually key.
                     error_log("Warning: Item '$item_path_full' is not writable, attempting delete based on parent permissions.");
                    // $http_status_code = 403; throw new Exception('Permission denied: Item is not writable.');
                 }

                 if (is_dir($item_path_full)) {
                     // Use recursive delete for directories
                     if (!recursive_delete($item_path_full)) {
                          $http_status_code = 500; // Or 403 if it's likely permissions inside
                          throw new Exception("Failed to delete directory '$item_relative_path'. Check permissions or if it contains unwritable items.");
                     }
                 } else {
                     // Delete file
                     if (!@unlink($item_path_full)) {
                         $http_status_code = 500; // Or 403
                         throw new Exception("Failed to delete file '$item_relative_path'. Check permissions.");
                     }
                 }
                 $response = ['success' => true, 'message' => 'Item deleted successfully.'];
                 $http_status_code = 200;
                 break;

            case 'paste':
                 $source_relative = trim($_POST['source_path'] ?? '');
                 $destination_dir_relative = trim($_POST['destination_dir'] ?? '');
                 $operation = trim($_POST['operation'] ?? ''); // 'cut' or 'copy'

                 if ((empty($source_relative) && $source_relative !== '0') || empty($operation)) {
                     $http_status_code = 400; throw new Exception('Missing source path or operation for paste.');
                 }

                 // --- FIX START ---
                 // The old code relied on $current_dir_full which was set by a URL parameter.
                 // The new code correctly uses the destination path sent in the POST request.

                 // Sanitize BOTH source and the POSTed destination directory
                 $source_path_full = sanitize_path($source_relative, $base_dir);
                 $destination_dir_full = sanitize_path($destination_dir_relative, $base_dir);
                 
                 // Explicitly check destination permissions now that we've correctly identified it
                 if (!is_writable($destination_dir_full)) {
                       $http_status_code = 403; throw new Exception("Permission denied: Destination directory '$destination_dir_relative/' is not writable.");
                 }
                 // --- FIX END ---

                 if ($source_path_full === false || $source_path_full === realpath($base_dir)) {
                     $http_status_code = 400; throw new Exception('Invalid source item specified for paste.');
                 }
                 
                 if ($destination_dir_full === false || !is_dir($destination_dir_full)) {
                     $http_status_code = 404; throw new Exception('Paste destination directory does not exist or is invalid.');
                 }

                 if (!file_exists($source_path_full)) {
                      $http_status_code = 404; throw new Exception('Source item does not exist or is inaccessible.');
                 }
                 if (!is_readable($source_path_full)) {
                     $http_status_code = 403; throw new Exception('Permission denied: Source item is not readable.');
                 }

                 $source_basename = basename($source_path_full);
                 $destination_path_full = $destination_dir_full . DIRECTORY_SEPARATOR . $source_basename;

                 // Sanitize the final destination path to be absolutely sure
                 $destination_path_full_sanitized = sanitize_path( ($destination_dir_relative ? $destination_dir_relative . DIRECTORY_SEPARATOR : '') . $source_basename, $base_dir);
                  if ($destination_path_full_sanitized === false || dirname($destination_path_full_sanitized) !== $destination_dir_full) {
                       $http_status_code = 400; throw new Exception('Paste destination path security check failed.');
                  }

                 // Prevent pasting onto itself
                 if (realpath($destination_path_full_sanitized) === realpath($source_path_full)) {
                      $http_status_code = 400; throw new Exception("Cannot {$operation} '{$source_basename}' onto itself.");
                 }
                 // Prevent pasting folder into itself/subfolder
                 $is_source_dir = is_dir($source_path_full);
                 if ($is_source_dir && strpos(realpath($destination_dir_full), realpath($source_path_full) . DIRECTORY_SEPARATOR) === 0) {
                    $http_status_code = 400; throw new Exception("Cannot {$operation} a folder into itself or a subdirectory of itself.");
                 }

                 if (file_exists($destination_path_full_sanitized)) {
                     $http_status_code = 409; // Conflict
                     throw new Exception("An item named '{$source_basename}' already exists in the destination. Cannot overwrite.");
                 }

                 // --- Perform Copy or Cut ---
                 if ($operation === 'copy') {
                     if (recursive_copy($source_path_full, $destination_path_full_sanitized)) {
                         $response = ['success' => true, 'message' => "Item '{$source_basename}' copied successfully."];
                         $http_status_code = 201; // Created
                     } else {
                         $http_status_code = 500; throw new Exception("Failed to copy '{$source_basename}'. Check server logs and permissions.");
                     }
                 } elseif ($operation === 'cut') {
                     if (!is_writable($source_path_full) || !is_writable(dirname($source_path_full))) {
                         $http_status_code = 403; throw new Exception("Permission denied: Cannot move '{$source_basename}', source item or its directory not writable.");
                     }
                     if (@rename($source_path_full, $destination_path_full_sanitized)) {
                         $response = ['success' => true, 'message' => "Item '{$source_basename}' moved successfully."];
                         $http_status_code = 200;
                     } else {
                         $last_error = error_get_last();
                         $error_detail = $last_error ? $last_error['message'] : 'Unknown reason.';
                         if ($last_error && $last_error['type'] === E_WARNING && stripos($error_detail, 'cross-device link') !== false) {
                             $error_detail = 'Cannot move across different storage devices/partitions.';
                             $http_status_code = 500;
                         } else {
                              $http_status_code = 500;
                         }
                         throw new Exception("Failed to move item '{$source_basename}'. Reason: {$error_detail} Check permissions or if moving across devices.");
                     }
                 } else {
                     $http_status_code = 400; throw new Exception('Invalid paste operation specified.');
                 }
                 break;

            case 'view': // AJAX request for view metadata/text content
                $file_relative_path = trim($_REQUEST['path'] ?? '');
                if (empty($file_relative_path) && $file_relative_path !== '0') {
                    $http_status_code = 400; throw new Exception('No file path specified for viewing.');
                }

                $file_path_full = sanitize_path($file_relative_path, $base_dir);

                if ($file_path_full === false || !is_file($file_path_full)) {
                     $http_status_code = 404; throw new Exception('File not found or is not a file.');
                }
                 if (!is_readable($file_path_full)) {
                     $http_status_code = 403; throw new Exception('Permission denied: File is not readable.');
                 }
                 // Security check handled by sanitize_path

                // --- Determine MIME Type and View Type ---
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = $finfo ? finfo_file($finfo, $file_path_full) : 'application/octet-stream';
                finfo_close($finfo);

                $filename = basename($file_path_full);
                $view_type = 'unsupported'; // Default
                $content = null; $view_url = null;

                // Define viewable types (match JS definitions)
                $text_mime_prefixes = ['text/'];
                $text_mime_exact = ['application/json', 'application/xml', 'application/javascript', 'application/x-php', 'application/x-shellscript']; // Add more as needed
                $image_mime_prefixes = ['image/'];
                $video_mime_prefixes = ['video/'];
                $audio_mime_prefixes = ['audio/'];
                $pdf_mime_exact = ['application/pdf'];

                // Check Text (prioritize exact matches)
                if (in_array($mime_type, $text_mime_exact)) $view_type = 'text';
                if ($view_type !== 'text') foreach ($text_mime_prefixes as $prefix) if (strpos($mime_type, $prefix) === 0) { $view_type = 'text'; break; }
                // Check Image
                if ($view_type === 'unsupported') foreach ($image_mime_prefixes as $prefix) if (strpos($mime_type, $prefix) === 0) { $view_type = 'image'; break; }
                // Check Video
                if ($view_type === 'unsupported') foreach ($video_mime_prefixes as $prefix) if (strpos($mime_type, $prefix) === 0) { $view_type = 'video'; break; }
                // Check Audio
                if ($view_type === 'unsupported') foreach ($audio_mime_prefixes as $prefix) if (strpos($mime_type, $prefix) === 0) { $view_type = 'audio'; break; }
                 // Check PDF
                if ($view_type === 'unsupported' && in_array($mime_type, $pdf_mime_exact)) $view_type = 'pdf';

                // --- Prepare Response Content/URL ---
                if ($view_type === 'text') {
                    $filesize = @filesize($file_path_full);
                    if ($filesize === false) { $http_status_code = 500; throw new Exception("Cannot determine file size for viewing."); }
                    if ($filesize > MAX_TEXT_VIEW_SIZE) {
                        $http_status_code = 413; throw new Exception("Cannot view text file: File is too large (>" . format_bytes_php(MAX_TEXT_VIEW_SIZE) . "). Download instead.");
                    }
                    $content_raw = @file_get_contents($file_path_full);
                    if ($content_raw === false) { $http_status_code = 500; throw new Exception('Failed to read file content.'); }
                    // Ensure content is UTF-8
                    if (!mb_check_encoding($content_raw, 'UTF-8')) {
                        $content_raw = mb_convert_encoding($content_raw, 'UTF-8', mb_detect_encoding($content_raw, 'UTF-8, ISO-8859-1, Windows-1252', true));
                    }
                    $content = htmlspecialchars($content_raw, ENT_QUOTES, 'UTF-8');

                } elseif (in_array($view_type, ['image', 'video', 'audio', 'pdf'])) {
                    // Provide the URL for the JS to use for direct fetching (triggers inline serving logic)
                    $view_url = "?action=view&path=" . rawurlencode($file_relative_path) . "&inline=1&t=" . time();

                } else {
                     $http_status_code = 415; // Unsupported Media Type
                     throw new Exception("Preview is not available for this file type ($mime_type). You can download it instead.");
                 }

                 // --- Send JSON Response ---
                 $response = [
                     'success' => true, 'filename' => $filename, 'mime_type' => $mime_type,
                     'view_type' => $view_type, 'content' => $content, 'view_url' => $view_url
                 ];
                 $http_status_code = 200;
                break; // End of case 'view'


             default:
                 $http_status_code = 400;
                 throw new Exception('Invalid action specified.');
        }

        // If we reached here without error, assume success with the determined status code
         http_response_code($http_status_code ?: 200); // Default success to 200 if not set

    } catch (Exception $e) {
        // Error occurred, ensure correct HTTP status code is set
        http_response_code($http_status_code ?: 500); // Use determined code or default to 500
        $response = ['success' => false, 'message' => $e->getMessage()];
        // Log the detailed error server-side
        error_log("File Manager Error (Action: $action): " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    }

    // --- Output JSON Response ---
    // Clear buffer just in case and output JSON
    ob_clean();
    echo json_encode($response);
    exit; // Stop script execution after handling AJAX/POST

} elseif ($action === 'download') {
    // --- Download logic (Force download) ---
    $file_to_download_relative = trim($_GET['path'] ?? '');

    $file_path_full = sanitize_path($file_to_download_relative, $base_dir);

    if ($file_path_full === false || !is_file($file_path_full) || !is_readable($file_path_full)) {
         http_response_code(404); die("Error: File not found or is not readable.");
    }
    // Security check handled by sanitize_path

    $filename = basename($file_path_full);
    $filesize = @filesize($file_path_full);

    // Set headers to force download
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream'); // Generic type
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"'); // Raw URL encode filename
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    if ($filesize !== false) header('Content-Length: ' . $filesize);

    ob_clean(); flush();
    $bytes_read = @readfile($file_path_full);
    if ($bytes_read === false || ($bytes_read === 0 && $filesize > 0)) {
        error_log("Download error: Failed to read file '$file_path_full'");
    }
    exit; // Stop script execution after download
}

// --- HTML & JAVASCRIPT FRONTEND ---
// Render the HTML page if no specific action above handled the request and exited.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Manager</title>
    <style>
        /* --- Basic Styling --- */
        :root {
            --primary-color: #007bff;
            --secondary-color: #6c757d;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --border-color: #dee2e6;
            --body-bg: #f8f9fa;
            --body-color: #212529;
            --link-color: #007bff;
            --link-hover-color: #0056b3;
            --font-family-sans-serif: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
            --modal-backdrop-bg: rgba(0,0,0,0.6);
            --selection-bg: #cfe2ff;
        }
        body { font-family: var(--font-family-sans-serif); line-height: 1.6; margin: 20px; background-color: var(--body-bg); color: var(--body-color); font-size: 16px; }
        .container { background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); max-width: 1000px; margin: auto; transition: box-shadow 0.3s ease; }
        /* NEW: Drag-and-drop visual feedback */
        .drag-over-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(13, 110, 253, 0.1); border: 3px dashed var(--primary-color); z-index: 9999; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--primary-color); font-weight: bold; pointer-events: none; }

        h1 { color: var(--dark-color); border-bottom: 1px solid var(--border-color); padding-bottom: 10px; margin-bottom: 20px; font-weight: 500; font-size: 1.75rem; }
        h2 { color: var(--dark-color); margin-top: 30px; border-bottom: 1px solid #e9ecef; padding-bottom: 8px; font-size: 1.3rem; font-weight: 500; }
        ul { list-style: none; padding: 0; margin: 0; }
        li { border-bottom: 1px solid #e9ecef; padding: 10px 5px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; transition: background-color 0.2s ease; cursor: pointer;}
        li:last-child { border-bottom: none; }
        li:hover { background-color: #f1f3f5; }
        li.selected { background-color: var(--selection-bg); } /* NEW: Style for selected rows */
        li.selected:hover { background-color: #b8d4fe; }
        li span.name { flex-grow: 1; margin-right: 10px; min-width: 150px; word-break: break-all; display: flex; align-items: center; font-size: 0.95rem; pointer-events: none; /* Make text non-clickable so row click works */}
        li span.name a { pointer-events: auto; /* Re-enable pointer events for links */}
        li span.name a, li span.actions button, li span.actions a { cursor: pointer; } /* Ensure children are clickable */
        li .file-selector { margin-right: 12px; cursor: pointer; flex-shrink: 0; }
        li span.name a { text-decoration: none; color: var(--link-color); font-weight: 500; }
        li span.name a:hover { text-decoration: underline; color: var(--link-hover-color); }
        li span.size { min-width: 100px; text-align: right; font-size: 0.85rem; color: var(--secondary-color); flex-shrink: 0;}
        li span.actions { min-width: 200px; text-align: right; flex-shrink: 0; display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap;}
        /* --- Action Buttons --- */
        li span.actions button, li span.actions .action-link, .actions-section button, #batchActions button { /* Base style */
             padding: 4px 9px; font-size: 0.8rem; cursor: pointer; border: 1px solid #ced4da; background-color: #f8f9fa; border-radius: 4px; color: #495057; text-decoration: none; display: inline-block; white-space: nowrap; transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease; font-weight: 500; line-height: 1.5; vertical-align: middle;
         }
        li span.actions button:hover, li span.actions .action-link:hover, .actions-section button:hover, #batchActions button:hover { background-color: #e9ecef; border-color: #adb5bd; }
         /* Specific Button Colors */
         li span.actions .view-btn { border-color: #b0c4de; color: #4682b4; background-color: #e6eef9; }
         li span.actions .view-btn:hover { background-color: #d0dfee; border-color: #98afca; color: #3a6d9a; }
         li span.actions .download-btn { border-color: #a1c9f4; color: var(--primary-color); background-color: #cfe2ff; }
         li span.actions .download-btn:hover { background-color: #b8d4fe; border-color: #80b6f4; color: var(--link-hover-color); }
         li span.actions .copy-btn, #batchActions .copy-btn { border-color: #9eeaf9; color: var(--info-color); background-color: #d1ecf1; }
         li span.actions .copy-btn:hover, #batchActions .copy-btn:hover { background-color: #bce6f0; border-color: #86d8e9; color: #117a8b; }
         li span.actions .cut-btn, #batchActions .cut-btn { border-color: #ffeeba; color: var(--warning-color); background-color: #fff3cd; }
         li span.actions .cut-btn:hover, #batchActions .cut-btn:hover { background-color: #ffe8a1; border-color: #ffdf7e; color: #cc9a06; }
         li span.actions button.delete-btn, #batchActions button.delete-btn { border-color: #e4a3ab; color: var(--danger-color); background-color: #f8d7da; }
         li span.actions button.delete-btn:hover, #batchActions button.delete-btn:hover { background-color: #f1c0c5; border-color: #d98da0; color: #b02a37; }

        /* --- Icons --- */
        .icon { display: inline-block; width: 24px; text-align: center; margin-right: 8px; font-size: 1.1em; vertical-align: middle; }
        .icon-folder::before { content: '📁'; color: #ffc107; } /* Folder */
        .icon-file::before { content: '📄'; color: #6c757d; } /* Default File */
        .icon-file[data-ext="jpg"]::before, .icon-file[data-ext="jpeg"]::before, .icon-file[data-ext="png"]::before, .icon-file[data-ext="gif"]::before, .icon-file[data-ext="bmp"]::before, .icon-file[data-ext="svg"]::before, .icon-file[data-ext="webp"]::before { content: '🖼️'; color: #17a2b8; } /* Image */
        .icon-file[data-ext="mp4"]::before, .icon-file[data-ext="webm"]::before, .icon-file[data-ext="ogv"]::before, .icon-file[data-ext="mov"]::before, .icon-file[data-ext="avi"]::before, .icon-file[data-ext="wmv"]::before { content: '🎬'; color: #6f42c1; } /* Video */
        .icon-file[data-ext="mp3"]::before, .icon-file[data-ext="wav"]::before, .icon-file[data-ext="ogg"]::before, .icon-file[data-ext="m4a"]::before, .icon-file[data-ext="flac"]::before { content: '🎵'; color: #fd7e14; } /* Audio */
        .icon-file[data-ext="pdf"]::before { content: '📕'; color: #dc3545; } /* PDF */
        .icon-file[data-ext="zip"]::before, .icon-file[data-ext="rar"]::before, .icon-file[data-ext="gz"]::before, .icon-file[data-ext="tar"]::before, .icon-file[data-ext="7z"]::before { content: '📦'; color: #ffc107; } /* Archive */
        .icon-file[data-ext="txt"]::before, .icon-file[data-ext="log"]::before, .icon-file[data-ext="md"]::before, .icon-file[data-ext="csv"]::before { content: '📝'; color: #6c757d; } /* Text */
        .icon-file[data-ext="js"]::before, .icon-file[data-ext="json"]::before, .icon-file[data-ext="css"]::before, .icon-file[data-ext="html"]::before, .icon-file[data-ext="php"]::before, .icon-file[data-ext="py"]::before, .icon-file[data-ext="sql"]::before, .icon-file[data-ext="xml"]::before, .icon-file[data-ext="java"]::before, .icon-file[data-ext="sh"]::before, .icon-file[data-ext="yml"]::before { content: '💻'; color: #007bff; } /* Code */
        .icon-file[data-ext="doc"]::before, .icon-file[data-ext="docx"]::before { content: ' W'; color: #2b579a; font-weight: bold; } /* Word */
        .icon-file[data-ext="xls"]::before, .icon-file[data-ext="xlsx"]::before { content: ' X'; color: #1e7145; font-weight: bold; } /* Excel */
        .icon-file[data-ext="ppt"]::before, .icon-file[data-ext="pptx"]::before { content: ' P'; color: #d04423; font-weight: bold; } /* PowerPoint */
        .icon-back::before { content: '↩️'; color: #007bff; } /* Back */

        /* --- Forms & Controls --- */
        .form-section, .actions-section { margin-top: 25px; padding-top: 20px; border-top: 1px solid var(--border-color); }
        label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 0.95rem; color: #495057; }
        input[type="text"], input[type="file"] { display: block; width: calc(100% - 24px); padding: 9px 12px; margin-bottom: 12px; border: 1px solid #ced4da; border-radius: 4px; background-color: #fff; font-size: 0.95rem; transition: border-color 0.2s ease, box-shadow 0.2s ease; }
        input[type="text"]:focus, input[type="file"]:focus { border-color: #86b7fe; outline: 0; box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25); }
        input[type="file"] { padding: 6px 12px; line-height: 1.5; }
        button[type="submit"], .actions-section button { padding: 9px 16px; background-color: var(--success-color); color: white; border: none; border-radius: 4px; cursor: pointer; margin-right: 10px; font-size: 0.95rem; font-weight: 500; transition: background-color 0.2s ease; }
        button[type="submit"]:hover, .actions-section button:hover { background-color: #218838; }
        button:disabled { background-color: #adb5bd; cursor: not-allowed; opacity: 0.7;}
        #pasteButton { background-color: var(--primary-color); }
        #pasteButton:hover:not(:disabled) { background-color: var(--link-hover-color); }
        #pasteButton:disabled { background-color: var(--secondary-color); }
        #currentPath { margin-bottom: 20px; font-weight: 600; color: #495057; word-break: break-all; background-color: #e9ecef; padding: 10px 15px; border-radius: 5px; font-size: 1.05rem;}
        #clipboardStatus { font-size: 0.9rem; color: var(--secondary-color); margin-left: 15px; font-style: italic; }
        /* NEW: Batch Actions Bar */
        #batchActions { display: none; margin-top: 15px; padding: 10px; background-color: #e9ecef; border-radius: 5px; border: 1px solid var(--border-color); }
        #batchActions span { font-weight: bold; margin-right: 15px; vertical-align: middle; }
        #batchActions button { font-size: 0.85rem; padding: 6px 12px; margin-right: 5px; }
        .list-header { display: flex; align-items: center; padding: 5px; border-bottom: 2px solid var(--border-color); margin-bottom: 5px; }
        .list-header input[type="checkbox"] { margin-right: 12px; }
        .list-header label { margin: 0; font-weight: bold; }

        /* --- Status & Progress --- */
        #statusMessage { margin-top: 20px; padding: 12px 15px; border-radius: 5px; display: none; word-break: break-word; font-size: 0.95rem; }
        #statusMessage.success { background-color: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
        #statusMessage.error { background-color: #f8d7da; color: #842029; border: 1px solid #f5c2c7; }
        #statusMessage.info { background-color: #cfe2ff; color: #055160; border: 1px solid #b6d4fe; }
        .progress-container { margin-top: 15px; padding: 10px; border: 1px solid var(--border-color); border-radius: 5px; background-color: var(--light-color); display: none; }
        .progress-item { margin-bottom: 8px; } .progress-item:last-child { margin-bottom: 0; }
        .progress-label { display: block; font-size: 0.9rem; color: #495057; margin-bottom: 4px; word-break: break-all; }
        .progress-bar-outer { background-color: #e9ecef; border-radius: 4px; height: 14px; overflow: hidden; position: relative; }
        .progress-bar-inner { background-color: var(--primary-color); height: 100%; width: 0%; border-radius: 4px; transition: width 0.3s ease-out; text-align: center; line-height: 14px; color: white; font-size: 0.75rem; font-weight: bold; }
        .progress-bar-inner.indeterminate { width: 100% !important; background: linear-gradient(to right, #e9ecef 20%, var(--primary-color) 50%, #e9ecef 80%); background-size: 200% 100%; animation: indeterminate-progress 1.5s linear infinite; }
        @keyframes indeterminate-progress { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        .progress-details { font-size: 0.8rem; color: var(--secondary-color); margin-top: 4px; }

        /* --- File View Modal Styling --- */
        #fileViewModal { display: none; position: fixed; z-index: 1050; /* Higher z-index */ left: 0; top: 0; width: 100%; height: 100%; overflow: hidden; background-color: var(--modal-backdrop-bg); outline: 0; }
        .modal-content { position: relative; display: flex; flex-direction: column; width: 90%; max-width: 1100px; pointer-events: auto; background-color: #fff; background-clip: padding-box; border: 1px solid rgba(0,0,0,.2); border-radius: .5rem; outline: 0; margin: 1.75rem auto; /* Centering with margin */ max-height: calc(100vh - 3.5rem); }
        .modal-header { display: flex; flex-shrink: 0; align-items: center; justify-content: space-between; padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-color); border-top-left-radius: calc(.5rem - 1px); border-top-right-radius: calc(.5rem - 1px); }
        #modalFileName { margin: 0; font-size: 1.25rem; color: var(--dark-color); font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; padding-right: 1rem; }
        #modalCloseButton { background: transparent; border: 0; font-size: 1.8rem; font-weight: 700; line-height: 1; color: #000; text-shadow: 0 1px 0 #fff; opacity: .5; padding: 0.5rem; margin: -0.5rem -0.5rem -0.5rem auto; cursor: pointer; }
        #modalCloseButton:hover { opacity: .75; }
        #modalFileContentWrapper { position: relative; flex: 1 1 auto; /* Allow grow/shrink */ overflow: auto; padding: 1.5rem; background-color: #fff; display: flex; justify-content: center; align-items: center; text-align: center; } /* Center content */
        /* Content specific styles */
        #modalFileContentWrapper pre { background-color: #fdfdfd; border: 1px solid #eee; padding: 15px; border-radius: 4px; white-space: pre-wrap; word-wrap: break-word; font-family: Consolas, Monaco, 'Andale Mono', 'Ubuntu Mono', monospace; font-size: 0.9rem; line-height: 1.5; color: #222; margin: 0; width: 100%; max-width: 100%; text-align: left; }
        #modalFileContentWrapper img,
        #modalFileContentWrapper video,
        #modalFileContentWrapper audio,
        #modalFileContentWrapper iframe { max-width: 100%; max-height: calc(100vh - 10rem); /* Adjust max height dynamically */ height: auto; display: block; margin: auto; border: none; }
        #modalFileContentWrapper video,
        #modalFileContentWrapper audio { min-width: 300px; background-color: #f0f0f0; /* Lighter bg */ }
        #modalFileContentWrapper iframe { width: 100%; height: calc(100vh - 10rem); } /* Explicit height for iframe */
        .modal-loading { font-size: 1.1rem; color: #666; }
        .modal-loading.error { color: var(--danger-color); font-weight: bold; }

    </style>
</head>
<body>

<div class="container">
    <h1>File Manager</h1>

    <div id="currentPath">Loading...</div>

    <div class="actions-section">
        <button id="pasteButton" disabled title="Paste item from clipboard into this directory">Paste Here</button>
        <span id="clipboardStatus">Clipboard is empty.</span>
    </div>
    
    <!-- NEW: Batch Actions Bar -->
    <div id="batchActions">
        <span id="selectionCount"></span>
        <button class="copy-btn" id="batchCopy" title="Copy selected items to clipboard">Copy Selected</button>
        <button class="cut-btn" id="batchCut" title="Cut selected items to clipboard">Cut Selected</button>
        <button class="delete-btn" id="batchDelete" title="Delete all selected items">Delete Selected</button>
    </div>

    <div id="progressArea" class="progress-container">
        <!-- Progress items will be added here -->
    </div>

    <div class="list-header">
        <input type="checkbox" id="selectAllCheckbox" title="Select/Deselect All">
        <label for="selectAllCheckbox">Select All</label>
    </div>
    <ul id="fileList">
        <li>Loading...</li>
    </ul>

    <div class="form-section">
        <h2>Upload Files (or Drag & Drop anywhere)</h2>
        <form id="uploadForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload">
            <input type="hidden" id="uploadDir" name="dir" value="">
            <label for="filesToUpload">Select files to upload:</label>
            <input type="file" name="filesToUpload[]" id="filesToUpload" multiple required>
            <button type="submit">Upload Files</button>
        </form>
    </div>

    <div class="form-section">
        <h2>Create New Folder</h2>
        <form id="createFolderForm">
             <input type="hidden" name="action" value="create_folder">
             <input type="hidden" id="createFolderDir" name="dir" value="">
             <label for="folder_name">Folder name:</label>
            <input type="text" name="folder_name" id="folder_name" required pattern="^[^\\/:*?\"<>|.]+$" title="Enter a valid folder name (no slashes, colons, etc., or just dots)">
            <button type="submit">Create Folder</button>
        </form>
    </div>

    <div id="statusMessage"></div>

    <!-- File View Modal Structure -->
    <div id="fileViewModal" tabindex="-1" aria-labelledby="modalFileName" aria-hidden="true">
        <div class="modal-content">
            <div class="modal-header">
                 <h3 id="modalFileName">File Viewer</h3>
                 <button type="button" id="modalCloseButton" aria-label="Close">×</button>
             </div>
            <div id="modalFileContentWrapper">
                 <span class="modal-loading">Loading...</span> <!-- Initial loading text -->
            </div>
        </div>
    </div>

</div><!-- End of container -->

<script>
    // --- Element References ---
    const container = document.querySelector('.container');
    const fileList = document.getElementById('fileList');
    const uploadForm = document.getElementById('uploadForm');
    const createFolderForm = document.getElementById('createFolderForm');
    const statusMessage = document.getElementById('statusMessage');
    const progressArea = document.getElementById('progressArea');
    const currentPathDiv = document.getElementById('currentPath');
    const uploadDirInput = document.getElementById('uploadDir');
    const createFolderDirInput = document.getElementById('createFolderDir');
    const pasteButton = document.getElementById('pasteButton');
    const clipboardStatusDiv = document.getElementById('clipboardStatus');
    const fileViewModal = document.getElementById('fileViewModal');
    const modalContentDiv = fileViewModal.querySelector('.modal-content');
    const modalFileName = document.getElementById('modalFileName');
    const modalFileContentWrapper = document.getElementById('modalFileContentWrapper');
    const modalCloseButton = document.getElementById('modalCloseButton');
    // NEW: Selection and Batch Action elements
    const batchActionsBar = document.getElementById('batchActions');
    const selectionCountSpan = document.getElementById('selectionCount');
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const batchCopyBtn = document.getElementById('batchCopy');
    const batchCutBtn = document.getElementById('batchCut');
    const batchDeleteBtn = document.getElementById('batchDelete');

    let currentDirectory = ''; // Global JS state
    let clipboard = { sourcePaths: [], sourceNames: [], operation: null }; // Global clipboard state

    // --- Constants (Client-side hints) ---
    const VIEWABLE_TEXT_EXT = ['txt', 'log', 'csv', 'md', 'xml', 'json', 'js', 'css', 'html', 'htm', 'php', 'py', 'java', 'c', 'cpp', 'h', 'hpp', 'sh', 'sql', 'yaml', 'yml', 'ini', 'cfg', 'conf', 'bat', 'vbs', 'rb', 'pl'];
    const VIEWABLE_MEDIA_EXT = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp', 'mp4', 'webm', 'ogg', 'ogv', 'mov', 'avi', 'wmv', 'mp3', 'wav', 'm4a', 'flac', 'pdf'];

    // --- Helper Functions ---
    const formatBytes = (bytes, decimals = 2) => {
        if (bytes === null || bytes === undefined || bytes < 0 || isNaN(bytes)) return '';
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        const sizeIndex = Math.min(i, sizes.length - 1);
        return parseFloat((bytes / Math.pow(k, sizeIndex)).toFixed(dm)) + ' ' + sizes[sizeIndex];
    };

    const showStatus = (message, type = 'info', duration = 7000) => {
        statusMessage.textContent = message;
        statusMessage.className = type; // Use class directly
        statusMessage.style.display = 'block';
        // Clear previous timer if any
        if (statusMessage.timer) clearTimeout(statusMessage.timer);
        // Auto-hide
        statusMessage.timer = setTimeout(() => {
             if (statusMessage.textContent === message) { // Hide only if message hasn't changed
                 statusMessage.style.display = 'none';
             }
        }, duration > 0 ? duration : (type === 'error' ? 10000 : 7000));
    };

    const showProgress = (id, label, progress = null, details = '') => {
        let item = document.getElementById(`progress-${id}`);
        if (!item) {
            item = document.createElement('div');
            item.id = `progress-${id}`;
            item.className = 'progress-item';
            // Use textContent for security against potential XSS in filenames/labels
            item.innerHTML = `
                <span class="progress-label"></span>
                <div class="progress-bar-outer">
                    <div class="progress-bar-inner"></div>
                </div>
                <div class="progress-details"></div>`;
            progressArea.appendChild(item);
            progressArea.style.display = 'block'; // Show the area
        }
        item.querySelector('.progress-label').textContent = label;
        const progressBarInner = item.querySelector('.progress-bar-inner');
        const progressDetails = item.querySelector('.progress-details');
        progressBarInner.classList.remove('indeterminate'); // Remove first
        if (progress === null || progress < 0) { // Indeterminate
            progressBarInner.style.width = '0%';
            progressBarInner.classList.add('indeterminate');
            progressBarInner.textContent = '';
            progressDetails.textContent = details || 'Processing...';
        } else {
            const percent = Math.min(100, Math.max(0, Math.round(progress)));
            progressBarInner.style.width = `${percent}%`;
            progressBarInner.textContent = ''; // Optional: `${percent}%` inside bar
            progressDetails.textContent = details || `${percent}% Complete`;
        }
    };

    const removeProgress = (id) => {
        const item = document.getElementById(`progress-${id}`);
        if (item) item.remove();
        if (progressArea.childElementCount === 0) progressArea.style.display = 'none';
    };

    const updateClipboardStatus = () => {
        if (clipboard.operation && clipboard.sourcePaths && clipboard.sourcePaths.length > 0) {
            pasteButton.disabled = false;
            const opText = clipboard.operation === 'cut' ? 'Cut' : 'Copied';
            const count = clipboard.sourcePaths.length;
            const itemText = count === 1 ? `'${clipboard.sourceNames[0]}'` : `${count} items`;
            clipboardStatusDiv.textContent = `${opText}: ${itemText}`;
            clipboardStatusDiv.style.fontWeight = 'bold'; clipboardStatusDiv.style.fontStyle = 'normal';
        } else {
            pasteButton.disabled = true;
            clipboardStatusDiv.textContent = 'Clipboard is empty.';
            clipboardStatusDiv.style.fontWeight = 'normal'; clipboardStatusDiv.style.fontStyle = 'italic';
        }
    };

    const handleFetchError = async (response, defaultMessage = 'An unknown error occurred.') => {
        let errorMessage = defaultMessage;
        try {
            const errorData = await response.json();
            errorMessage = errorData.message || defaultMessage;
        } catch (e) {
            // If response is not JSON, try to get text
            try {
                 const textError = await response.text();
                 // Only show text if it's reasonably short and not HTML
                 if (textError && textError.length < 200 && !textError.trim().startsWith('<')) {
                      errorMessage = `${response.statusText || defaultMessage}: ${textError}`;
                 } else {
                      errorMessage = `${response.statusText || defaultMessage} (Status: ${response.status})`;
                 }
            } catch (textE) {
                 errorMessage = `${response.statusText || defaultMessage} (Status: ${response.status})`;
            }
        }
        return errorMessage;
    };

    // --- Core Actions ---

    async function loadFiles(dir = '') {
        fileList.innerHTML = '<li><span class="modal-loading">Loading...</span></li>'; // Use loading class
        currentDirectory = dir;
        currentPathDiv.textContent = `Current Path: /${dir || ''}`;
        uploadDirInput.value = dir; createFolderDirInput.value = dir;
        updateClipboardStatus(); // Reflects current dir change for paste validity
        updateSelectionState(); // Clear selection on load

        try {
            const cacheBust = Date.now();
            const response = await fetch(`?action=list&dir=${encodeURIComponent(dir)}&ajax=1&t=${cacheBust}`);

            if (!response.ok) {
                const errorMsg = await handleFetchError(response, 'Failed to load file list.');
                throw new Error(errorMsg);
            }
            const data = await response.json();

            if (!data.success) {
                 throw new Error(data.message || 'Server reported an error loading files.');
            }

            fileList.innerHTML = ''; // Clear loading/previous list
            // Update current dir based on sanitized response from server
            currentDirectory = data.current_dir !== null ? data.current_dir : '';
            currentPathDiv.textContent = `Current Path: /${currentDirectory}`;
            uploadDirInput.value = currentDirectory; createFolderDirInput.value = currentDirectory;

            if (!data.files || data.files.length === 0) {
                  fileList.innerHTML = '<li><em>Empty directory</em></li>';
             }

            data.files.forEach(file => {
                const li = document.createElement('li');
                li.setAttribute('data-path', file.path);
                li.setAttribute('data-name', file.name);

                const fileExtension = !file.is_dir && file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';
                const iconClass = file.is_dir ? (file.is_back ? 'icon-back' : 'icon-folder') : 'icon-file';
                const iconHtml = `<span class="icon ${iconClass}" ${!file.is_dir ? `data-ext="${fileExtension}"` : ''}></span>`;
                
                let checkboxHtml = '';
                if (!file.is_back) {
                    checkboxHtml = `<input type="checkbox" class="file-selector" data-path="${file.path}" data-name="${file.name}">`;
                }

                let nameHtml;
                if (file.is_back || file.is_dir) {
                     nameHtml = `${iconHtml} <a href="#" data-path="${file.path}" title="Open ${file.name}">${file.name}</a>`;
                } else {
                     nameHtml = `${iconHtml} <span title="${file.name}">${file.name}</span>`;
                }

                // Actions
                let actionsHtml = '';
                if (!file.is_back) {
                    // View Action
                    if (!file.is_dir && (VIEWABLE_TEXT_EXT.includes(fileExtension) || VIEWABLE_MEDIA_EXT.includes(fileExtension))) {
                         actionsHtml += `<button class="action-link view-btn" data-path="${file.path}" data-name="${file.name}" title="View ${file.name}">View</button>`;
                    }
                    // Download Action
                     if (!file.is_dir) {
                        actionsHtml += `<button class="action-link download-btn" data-path="${file.path}" data-name="${file.name}" data-size="${file.size}" title="Download ${file.name}">Download</button>`;
                     }
                    // Other Actions
                    actionsHtml += `<button class="copy-btn" data-path="${file.path}" data-name="${file.name}" title="Copy">Copy</button>`;
                    actionsHtml += `<button class="cut-btn" data-path="${file.path}" data-name="${file.name}" title="Cut">Cut</button>`;
                    actionsHtml += `<button class="delete-btn" data-path="${file.path}" data-name="${file.name}" title="Delete">Delete</button>`;
                }

                const sizeText = file.size === -1 ? 'Unknown' : formatBytes(file.size);
                li.innerHTML = `
                    ${checkboxHtml}
                    <span class="name">${nameHtml}</span>
                    <span class="size">${file.is_dir ? (file.is_back ? '' : '<DIR>') : sizeText}</span>
                    <span class="actions">${actionsHtml || ' '}</span>
                `;
                fileList.appendChild(li);
            });
            updateSelectionState(); // Final check after render

        } catch (error) {
             console.error('Load Files Fetch Error:', error);
             showStatus(`Error loading files: ${error.message}`, 'error', 10000);
             fileList.innerHTML = `<li>Error loading files list.</li>`;
        }
    }

     function copyItem(sourcePath, sourceName) {
         clipboard = { sourcePaths: [sourcePath], sourceNames: [sourceName], operation: 'copy' };
         updateClipboardStatus();
         showStatus(`'${sourceName}' copied. Navigate and paste.`, 'info');
     }

     function cutItem(sourcePath, sourceName) {
         clipboard = { sourcePaths: [sourcePath], sourceNames: [sourceName], operation: 'cut' };
         updateClipboardStatus();
         showStatus(`'${sourceName}' cut. Navigate and paste.`, 'info');
     }

    async function pasteItem() {
         if (!clipboard.operation || !clipboard.sourcePaths || clipboard.sourcePaths.length === 0) return;

         const { sourcePaths, sourceNames, operation } = clipboard; // Keep local copy
         const destinationDir = currentDirectory;
         const originalClipboard = { ...clipboard }; // For cut operation logic

         pasteButton.disabled = true;
         let successCount = 0;
         let errorCount = 0;

         for (let i = 0; i < sourcePaths.length; i++) {
             const sourcePath = sourcePaths[i];
             const sourceName = sourceNames[i];
             const progressId = `paste-${Date.now()}-${i}`;
             showProgress(progressId, `${operation === 'cut' ? 'Moving' : 'Copying'} '${sourceName}'...`, null, 'Initiating...');

             try {
                 const formData = new FormData();
                 formData.append('action', 'paste');
                 formData.append('source_path', sourcePath);
                 formData.append('destination_dir', destinationDir);
                 formData.append('operation', operation);

                 const response = await fetch('?ajax=1', { method: 'POST', body: formData });
                 const data = await response.json();

                 if (!response.ok || !data.success) {
                     throw new Error(data.message || `Server error ${response.status}`);
                 }
                 successCount++;
                 removeProgress(progressId);
             } catch (error) {
                 errorCount++;
                 console.error(`Paste Error for ${sourceName}:`, error);
                 removeProgress(progressId);
                 showStatus(`Paste failed for '${sourceName}': ${error.message}`, 'error');
             }
         }

         if (successCount > 0) {
             const itemText = successCount === 1 ? `'${sourceNames[0]}'` : `${successCount} items`;
             showStatus(`${itemText} ${operation === 'cut' ? 'moved' : 'copied'} successfully.`, 'success');
             if (operation === 'cut') {
                 clipboard = { sourcePaths: [], sourceNames: [], operation: null };
             }
         }
         if (errorCount > 0) {
            showStatus(`${errorCount} item(s) failed to paste.`, 'error', 10000);
         }
         
         loadFiles(currentDirectory);
         updateClipboardStatus();
     }

    async function deleteItem(itemPath, itemName) {
         if (!confirm(`Permanently delete '${itemName}'? This cannot be undone.`)) return;
         await deleteItems([{path: itemPath, name: itemName}]);
    }

    async function deleteItems(items) { // items is an array of {path, name}
        let successCount = 0;
        let errorCount = 0;
        showStatus(`Deleting ${items.length} item(s)...`, 'info', 0);

        for (const item of items) {
             try {
                 const formData = new FormData();
                 formData.append('action', 'delete');
                 formData.append('path', item.path);

                 const response = await fetch('?ajax=1', { method: 'POST', body: formData });
                 const data = await response.json();

                 if (!response.ok || !data.success) {
                     throw new Error(data.message || `Server error ${response.status}`);
                 }
                 successCount++;
                 // If the deleted item was in the clipboard, clear it
                 const clipboardIndex = clipboard.sourcePaths.indexOf(item.path);
                 if (clipboardIndex > -1) {
                     clipboard.sourcePaths.splice(clipboardIndex, 1);
                     clipboard.sourceNames.splice(clipboardIndex, 1);
                 }
             } catch (error) {
                  errorCount++;
                  console.error(`Delete Error for ${item.name}:`, error);
                  showStatus(`Delete failed for '${item.name}': ${error.message}`, 'error');
             }
        }
        
        if (successCount > 0) {
            showStatus(`${successCount} item(s) deleted successfully.`, 'success');
        }
        if (errorCount === 0) { // If all successful, clear the general "deleting" message
             statusMessage.style.display = 'none';
        }

        loadFiles(currentDirectory);
        updateClipboardStatus();
    }


    async function downloadFileWithProgress(filePath, fileName, fileSize) {
        const progressId = `dl-${fileName.replace(/[^a-z0-9]/gi, '_')}-${Date.now()}`;
        const url = `?action=download&path=${encodeURIComponent(filePath)}`;
        let receivedLength = 0;
        let chunks = [];

        showProgress(progressId, `Downloading ${fileName}`, 0, `Starting...`);

        try {
            const response = await fetch(url);
            if (!response.ok) {
                 const errorText = await handleFetchError(response, 'Download request failed.');
                 throw new Error(errorText);
            }

            const totalLength = parseInt(response.headers.get('Content-Length') || fileSize || '-1');
            if (!response.body) throw new Error('ReadableStream not available.');

            const reader = response.body.getReader();
            while (true) {
                const { done, value } = await reader.read();
                if (done) break;
                chunks.push(value);
                receivedLength += value.length;

                if (totalLength > 0) {
                    const progress = (receivedLength / totalLength) * 100;
                    const details = `${formatBytes(receivedLength)} / ${formatBytes(totalLength)}`;
                    showProgress(progressId, `Downloading ${fileName}`, progress, details);
                } else {
                    showProgress(progressId, `Downloading ${fileName}`, null, `${formatBytes(receivedLength)} received`);
                }
            }

            const blob = new Blob(chunks, { type: response.headers.get('Content-Type') || 'application/octet-stream' });
            const downloadUrl = URL.createObjectURL(blob);
            const a = document.createElement("a");
            a.style.display = 'none';
            a.href = downloadUrl;
            a.download = fileName;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(downloadUrl); // Clean up

            showProgress(progressId, `Downloaded ${fileName}`, 100, 'Complete');
             setTimeout(() => removeProgress(progressId), 3000); // Show success briefly

        } catch (error) {
            console.error('Download Error:', error);
            showStatus(`Download error: ${error.message}`, 'error');
            removeProgress(progressId);
        }
    }

    // --- Modal ---
    function openModal() {
        fileViewModal.style.display = 'block';
        fileViewModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden'; // Prevent background scroll
         modalCloseButton.focus();
    }

    function closeModal() {
        fileViewModal.style.display = 'none';
        fileViewModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = ''; // Restore background scroll
        modalFileName.textContent = 'File Viewer'; // Reset title
        modalFileContentWrapper.innerHTML = '<span class="modal-loading">Loading...</span>'; // Reset content

        // Stop media playback and clean up src
        const mediaElement = modalFileContentWrapper.querySelector('video, audio');
        if (mediaElement) {
            mediaElement.pause();
            mediaElement.removeAttribute('src');
            try { mediaElement.load(); } catch(e) { /* Ignore potential errors */ }
        }
         // Clean up iframe src
         const iframeElement = modalFileContentWrapper.querySelector('iframe');
         if (iframeElement) {
             iframeElement.removeAttribute('src');
         }
    }

    async function viewItem(filePath, fileName) {
        modalFileName.textContent = `Loading ${fileName}...`;
        modalFileContentWrapper.innerHTML = '<span class="modal-loading">Loading...</span>';
        openModal();

        try {
            const cacheBust = Date.now();
            const response = await fetch(`?action=view&path=${encodeURIComponent(filePath)}&ajax=1&t=${cacheBust}`);
            const data = await response.json(); // Expect JSON response

            if (!response.ok || !data.success) {
                 throw new Error(data.message || `Server error (${response.status}) loading view data.`);
            }

            modalFileName.textContent = data.filename || fileName;
            modalFileContentWrapper.innerHTML = ''; // Clear loading indicator

            let element;
            switch(data.view_type) {
                case 'text':
                    element = document.createElement('pre');
                    element.textContent = data.content; // Use textContent for security
                    break;
                case 'image':
                    element = document.createElement('img');
                    element.src = data.view_url;
                    element.alt = data.filename;
                    element.onerror = () => { modalFileContentWrapper.innerHTML = '<span class="modal-loading error">Failed to load image.</span>'; };
                    break;
                case 'video':
                    element = document.createElement('video');
                    element.src = data.view_url;
                    element.controls = true;
                    element.preload = 'metadata';
                    element.onerror = () => { modalFileContentWrapper.innerHTML = '<span class="modal-loading error">Failed to load video. Unsupported format or network issue.</span>'; };
                    break;
                case 'audio':
                    element = document.createElement('audio');
                    element.src = data.view_url;
                    element.controls = true;
                    element.preload = 'metadata';
                    element.onerror = () => { modalFileContentWrapper.innerHTML = '<span class="modal-loading error">Failed to load audio.</span>'; };
                    break;
                case 'pdf':
                     element = document.createElement('iframe');
                     element.src = data.view_url;
                     element.title = `PDF Viewer: ${data.filename}`;
                     element.setAttribute('frameborder', '0');
                     element.onerror = () => { modalFileContentWrapper.innerHTML = '<span class="modal-loading error">Failed to load PDF. Browser PDF plugin might be disabled or file corrupted.</span>'; };
                    break;
                default:
                    throw new Error(`Unsupported view type: ${data.view_type}`);
            }
            modalFileContentWrapper.appendChild(element);

        } catch (error) {
            console.error('View Item Error:', error);
            modalFileName.textContent = `Error viewing ${fileName}`;
            modalFileContentWrapper.innerHTML = `<span class="modal-loading error">Failed to load file preview: ${error.message}</span>`;
        }
    }
    
    // --- NEW: Selection Logic ---
    function updateSelectionState() {
        const allCheckboxes = fileList.querySelectorAll('.file-selector');
        const selectedCheckboxes = fileList.querySelectorAll('.file-selector:checked');

        // Update LI styling
        fileList.querySelectorAll('li').forEach(li => {
            const cb = li.querySelector('.file-selector');
            if (cb) {
                li.classList.toggle('selected', cb.checked);
            }
        });

        if (selectedCheckboxes.length > 0) {
            selectionCountSpan.textContent = `${selectedCheckboxes.length} item(s) selected`;
            batchActionsBar.style.display = 'block';
        } else {
            batchActionsBar.style.display = 'none';
        }

        // Update "Select All" checkbox state
        if (allCheckboxes.length > 0 && selectedCheckboxes.length === allCheckboxes.length) {
            selectAllCheckbox.checked = true;
            selectAllCheckbox.indeterminate = false;
        } else if (selectedCheckboxes.length > 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = true;
        } else {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        }
    }
    
    function getSelectedItems() {
        const selected = [];
        fileList.querySelectorAll('.file-selector:checked').forEach(cb => {
            selected.push({ path: cb.dataset.path, name: cb.dataset.name });
        });
        return selected;
    }


    // --- NEW: Upload Handling for Multiple Files ---
    function handleFileUploads(files) {
        if (!files || files.length === 0) return;
        
        const submitButton = uploadForm.querySelector('button[type="submit"]');
        
        for (const file of files) {
            const formData = new FormData();
            formData.append('action', 'upload');
            formData.append('dir', currentDirectory);
            formData.append('fileToUpload', file);

            submitButton.disabled = true;
            const progressId = `up-${file.name.replace(/[^a-z0-9]/gi, '_')}-${Date.now()}`;

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '?ajax=1', true);

            xhr.upload.addEventListener('progress', (event) => {
                if (event.lengthComputable) {
                    const percent = (event.loaded / event.total) * 100;
                    const details = `${formatBytes(event.loaded)} / ${formatBytes(event.total)}`;
                    showProgress(progressId, `Uploading ${file.name}`, percent, details);
                } else {
                    showProgress(progressId, `Uploading ${file.name}`, null, `${formatBytes(event.loaded)} uploaded`);
                }
            });

            xhr.addEventListener('load', () => {
                submitButton.disabled = false;
                removeProgress(progressId);

                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        const data = JSON.parse(xhr.responseText);
                        if (data.success) {
                            showStatus(data.message, 'success');
                            loadFiles(currentDirectory);
                        } else {
                            showStatus(`'${file.name}' failed to upload: ${data.message}`, 'error', 10000);
                        }
                    } catch (parseError) {
                         console.error("Error parsing upload response:", parseError, "Response:", xhr.responseText);
                         showStatus(`Upload of '${file.name}' completed but received invalid server response.`, 'error');
                    }
                } else {
                     handleFetchError({ status: xhr.status, statusText: xhr.statusText, json: () => Promise.resolve(JSON.parse(xhr.responseText)), text: () => Promise.resolve(xhr.responseText) }, 'Upload request failed.')
                         .then(errorMsg => showStatus(`Upload of '${file.name}' failed: ${errorMsg}`, 'error'));
                }
            });

            xhr.addEventListener('error', () => {
                submitButton.disabled = false;
                removeProgress(progressId);
                showStatus(`Upload of '${file.name}' failed due to a network error.`, 'error');
            });
            
            xhr.addEventListener('abort', () => {
                 submitButton.disabled = false;
                 removeProgress(progressId);
            });

            showProgress(progressId, `Uploading ${file.name}`, 0, 'Starting...');
            xhr.send(formData);
        }
        uploadForm.reset();
    }

    // --- Form Submissions & Event Listeners ---
    uploadForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const fileInput = document.getElementById('filesToUpload');
        handleFileUploads(fileInput.files);
    });

    createFolderForm.addEventListener('submit', async (e) => {
        e.preventDefault();
         const folderNameInput = document.getElementById('folder_name');
         const folderName = folderNameInput.value.trim();
         if (!folderName || !folderNameInput.checkValidity()) {
             showStatus('Invalid folder name. Avoid special characters.', 'error');
             folderNameInput.focus();
             return;
         }

         const formData = new FormData(createFolderForm);
         formData.set('dir', currentDirectory);

         const submitButton = createFolderForm.querySelector('button[type="submit"]');
         submitButton.disabled = true;
         showStatus('Creating folder...', 'info', 0);

        try {
            const response = await fetch('?ajax=1', { method: 'POST', body: formData });
            const data = await response.json();

            if (!response.ok || !data.success) {
                 throw new Error(data.message || `Server error ${response.status}`);
            }

            showStatus(data.message, 'success');
            createFolderForm.reset();
            loadFiles(currentDirectory);

         } catch (error) {
             console.error('Create Folder Error:', error);
             showStatus(`Failed to create folder: ${error.message}`, 'error');
         } finally {
             submitButton.disabled = false;
         }
    });

    // --- Global Event Listeners ---

    // Modal Closing
    fileViewModal.addEventListener('click', (event) => { if (event.target === fileViewModal) closeModal(); });
    modalCloseButton.addEventListener('click', closeModal);
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && fileViewModal.style.display === 'block') closeModal(); });

    // Paste Button
    pasteButton.addEventListener('click', pasteItem);
    
    // --- NEW/CORRECTED: Event delegation for file list actions, selection, and navigation ---
    fileList.addEventListener('click', e => {
        const target = e.target;
        const li = target.closest('li');
        if (!li) return;

        const path = li.dataset.path;
        const name = li.dataset.name;

        // Handle action buttons
        if (target.tagName === 'BUTTON') {
            e.stopPropagation(); // Prevent row click from firing
            if (target.classList.contains('view-btn')) viewItem(path, name);
            if (target.classList.contains('download-btn')) downloadFileWithProgress(path, name, target.dataset.size);
            if (target.classList.contains('copy-btn')) copyItem(path, name);
            if (target.classList.contains('cut-btn')) cutItem(path, name);
            if (target.classList.contains('delete-btn')) deleteItem(path, name);
            return;
        }

        // Handle navigation link
        if (target.tagName === 'A') {
            e.preventDefault();
            loadFiles(target.dataset.path);
            return;
        }

        // Handle row click for selection
        const checkbox = li.querySelector('.file-selector');
        // --- THIS IS THE FIX ---
        // Only toggle if the click was not on the checkbox itself.
        if (checkbox && target.type !== 'checkbox') {
            checkbox.checked = !checkbox.checked;
            // Manually trigger change event for consistency, which then calls updateSelectionState()
            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });

    
    fileList.addEventListener('change', e => {
        if (e.target.classList.contains('file-selector')) {
            updateSelectionState();
        }
    });

    selectAllCheckbox.addEventListener('change', () => {
        const isChecked = selectAllCheckbox.checked;
        fileList.querySelectorAll('.file-selector').forEach(cb => {
            cb.checked = isChecked;
        });
        updateSelectionState();
    });

    batchCopyBtn.addEventListener('click', () => {
        const items = getSelectedItems();
        if (items.length > 0) {
            clipboard = {
                sourcePaths: items.map(i => i.path),
                sourceNames: items.map(i => i.name),
                operation: 'copy'
            };
            updateClipboardStatus();
            showStatus(`${items.length} item(s) copied.`, 'info');
        }
    });
    
    batchCutBtn.addEventListener('click', () => {
        const items = getSelectedItems();
        if (items.length > 0) {
            clipboard = {
                sourcePaths: items.map(i => i.path),
                sourceNames: items.map(i => i.name),
                operation: 'cut'
            };
            updateClipboardStatus();
            showStatus(`${items.length} item(s) cut.`, 'info');
        }
    });

    batchDeleteBtn.addEventListener('click', () => {
        const items = getSelectedItems();
        if (items.length > 0) {
            if (confirm(`Permanently delete these ${items.length} items? This cannot be undone.`)) {
                deleteItems(items);
            }
        }
    });

    // --- NEW: Drag and Drop Upload Listeners ---
    let dragCounter = 0;
    document.body.addEventListener('dragenter', e => {
        e.preventDefault(); e.stopPropagation();
        dragCounter++;
        if (dragCounter === 1 && !document.getElementById('drag-overlay')) {
            const overlay = document.createElement('div');
            overlay.id = 'drag-overlay';
            overlay.className = 'drag-over-overlay';
            overlay.textContent = 'Drop Files to Upload';
            document.body.appendChild(overlay);
        }
    });

    document.body.addEventListener('dragleave', e => {
        e.preventDefault(); e.stopPropagation();
        // Use a timeout to prevent flickering when moving over child elements
        setTimeout(() => {
            dragCounter--;
            if (dragCounter === 0) {
                document.getElementById('drag-overlay')?.remove();
            }
        }, 100);
    });

    document.body.addEventListener('dragover', e => {
        e.preventDefault(); e.stopPropagation();
    });

    document.body.addEventListener('drop', e => {
        e.preventDefault(); e.stopPropagation();
        dragCounter = 0;
        document.getElementById('drag-overlay')?.remove();
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            handleFileUploads(files);
        }
    });

    // --- Initial Load ---
    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const initialDir = urlParams.get('dir') || '';
        loadFiles(initialDir);
        updateClipboardStatus();
    });
 </script>

</body>
</html>
<?php
// --- END OF SCRIPT ---
?>