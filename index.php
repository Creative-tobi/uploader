<?php
// 1. ENVIRONMENT & PERSISTENCE CONFIG
ini_set('display_errors', 1);
error_reporting(E_ALL);

function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            putenv(trim($name) . "=" . trim($value));
        }
    }
}
loadEnv(__DIR__ . '/.env');

$cloudName = getenv('CLOUDINARY_CLOUD_NAME');
$uploadPreset = getenv('CLOUDINARY_UPLOAD_PRESET');
$uploadDir = 'uploads/';

if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$message = '';
$messageType = '';

// 2. HANDLE DELETE
if (isset($_GET['delete'])) {
    $fileToDelete = $uploadDir . basename($_GET['delete']);
    if (file_exists($fileToDelete)) {
        unlink($fileToDelete);
        header("Location: " . $_SERVER['PHP_SELF'] . "?status=deleted");
        exit();
    }
}

// 3. PERSISTENT UPLOAD LOGIC
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $originalName = preg_replace('/[^a-zA-Z0-9._-]/', '', $file['name']);
        
        $isDuplicate = false;
        if (is_dir($uploadDir)) {
            $existingFiles = array_diff(scandir($uploadDir), array('.', '..'));
            foreach ($existingFiles as $f) {
                if (strpos($f, $originalName) !== false) {
                    $isDuplicate = true;
                    break;
                }
            }
        }

        if ($isDuplicate) {
            $message = "⚠️ Access Denied: This file already exists in the portal.";
            $messageType = 'error';
        } else {
            // THE "REAL" UPLOAD DATE: We use the current timestamp
            $timestamp = time();
            $newFileName = $timestamp . '_' . $originalName;
            $destination = $uploadDir . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $boundary = "---------------------------" . microtime(true);
                $content = "--$boundary\r\n" .
                           "Content-Disposition: form-data; name=\"upload_preset\"\r\n\r\n" .
                           "$uploadPreset\r\n" .
                           "--$boundary\r\n" .
                           "Content-Disposition: form-data; name=\"file\"; filename=\"$originalName\"\r\n" .
                           "Content-Type: " . $file['type'] . "\r\n\r\n" .
                           file_get_contents($destination) . "\r\n" .
                           "--$boundary--\r\n";

                $options = ['http' => [
                    'header' => "Content-Type: multipart/form-data; boundary=$boundary\r\n",
                    'method' => 'POST',
                    'content' => $content,
                    'ignore_errors' => true
                ]];

                $context = stream_context_create($options);
                $result_json = file_get_contents("https://api.cloudinary.com/v1_1/$cloudName/auto/upload", false, $context);
                
                if ($result_json) {
                    header("Location: " . $_SERVER['PHP_SELF'] . "?status=success&name=" . urlencode($file['name']));
                    exit();
                } else {
                    $message = "Cloud Sync Failed, but local copy saved.";
                    $messageType = 'error';
                }
            }
        }
    }
}

if (empty($message) && isset($_GET['status'])) {
    if ($_GET['status'] == 'success') {
        $message = "🚀 [" . ($_GET['name'] ?? 'File') . "] Uploaded & Secured!";
        $messageType = 'success';
    } elseif ($_GET['status'] == 'deleted') {
        $message = "🗑️ Record Deleted Successfully.";
        $messageType = 'error';
    }
}

// 5. TABLE DATA
function getUploadedFiles($dir) {
    $files = [];
    if (is_dir($dir)) {
        $list = array_diff(scandir($dir), array('.', '..'));
        foreach ($list as $file) {
            if (is_file($dir . $file)) {
                // REAL DATE: Using filemtime for accurate upload time
                $files[] = [
                    'name' => $file,
                    'size' => filesize($dir . $file),
                    'date' => date('d M, Y | H:i', filemtime($dir . $file))
                ];
            }
        }
    }
    usort($files, function($a, $b) { return filemtime('uploads/'.$b['name']) - filemtime('uploads/'.$a['name']); });
    return $files;
}
$uploadedFiles = getUploadedFiles($uploadDir);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RCF ACADEMIC | SECURE PORTAL</title>
    <style>
        :root { --primary: #0062ff; --success: #198754; --danger: #e03131; --bg: #f0f2f5; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); padding: 20px; color: #1c1e21; }
        .container { max-width: 850px; margin: auto; background: #ffffff; padding: 40px; border-radius: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
        h1 { color: var(--primary); text-align: center; font-size: 2.2rem; font-weight: 800; margin-bottom: 30px; }
        
        .message { padding: 20px; border-radius: 12px; margin-bottom: 25px; text-align: center; font-weight: 700; border: 2px solid transparent; animation: pop 0.3s ease; transition: 0.5s; }
        .success { background: #e6fcf5; color: #099268; border-color: #c3fae8; }
        .error { background: #fff5f5; color: var(--danger); border-color: #ffe3e3; }

        .upload-area { background: #f8faff; border: 3px dashed #cbd5e0; padding: 40px; border-radius: 20px; text-align: center; margin-bottom: 40px; }
        .custom-label { background: #4b5563; color: white; padding: 14px 28px; border-radius: 10px; cursor: pointer; font-weight: 600; display: inline-block; transition: 0.2s; }
        
        .btn-push { background: var(--primary); color: white; padding: 18px; border: none; border-radius: 12px; cursor: pointer; font-size: 1.1rem; margin-top: 25px; font-weight: 800; width: 100%; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 10px; }
        .btn-push:disabled { background: #a5c7ff; cursor: not-allowed; }

        /* Spinner Animation */
        .spinner { width: 20px; height: 20px; border: 3px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 1s ease-in-out infinite; display: none; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes pop { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }

        table { width: 100%; border-collapse: separate; border-spacing: 0 8px; }
        th { padding: 12px; text-align: left; color: #64748b; font-size: 0.85rem; text-transform: uppercase; }
        td { padding: 18px; background: #ffffff; border-top: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9; }
        tr td:first-child { border-left: 1px solid #f1f5f9; border-radius: 15px 0 0 15px; font-weight: 800; color: #adb5bd; text-align: center; }
        tr td:last-child { border-right: 1px solid #f1f5f9; border-radius: 0 15px 15px 0; }

        .btn-dl { background: var(--success); color: white; text-decoration: none; padding: 10px 18px; border-radius: 10px; font-weight: 700; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 5px; }
        .btn-del { background: #fff1f0; border: 2px solid #ffa39e; padding: 10px; border-radius: 10px; cursor: pointer; }
    </style>
</head>
<body>

<div class="container">
    <div style="text-align:center;">
        <img src="rcf1.jpg" style="width:140px; border-radius:50%; border:6px solid #fff; box-shadow: 0 10px 25px rgba(0,0,0,0.15);">
    </div>
    <h1>RCF ACADEMIC PORTAL</h1>

    <?php if ($message): ?>
        <div id="alertBox" class="message <?= $messageType ?>"><?= $message ?></div>
    <?php endif; ?>

    <div class="upload-area">
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <input type="file" name="file" id="file-input" hidden required onchange="handleFileSelect(this)">
            <label for="file-input" class="custom-label">📂 Select Academic Resource</label>
            <span id="file-name" style="display:block; margin-top:20px; font-weight:800; color:var(--primary);">No file selected</span>
            
            <button type="submit" class="btn-push" id="submitBtn">
                <div class="spinner" id="uploadSpinner"></div>
                <span id="btnText">Upload Academic Resources</span>
            </button>
        </form>
    </div>

    <table>
        <thead>
            <tr><th width="50">S/N</th><th>RESOURCE DETAILS</th><th width="100">SIZE</th><th width="180" style="text-align:right;">ACTIONS</th></tr>
        </thead>
        <tbody>
            <?php $count = 1; foreach ($uploadedFiles as $f): ?>
            <tr>
                <td><?= $count++ ?></td>
                <td>
                    <span style="font-weight:800; color:#334155;"><?= htmlspecialchars(substr($f['name'], 11)) ?></span><br>
                    <small style="color:#94a3b8;"><?= $f['date'] ?></small>
                </td>
                <td style="font-weight:700; color:#64748b;"><?= round($f['size']/1024, 2) ?> KB</td>
                <td style="display:flex; gap:10px; justify-content:flex-end;">
                    <a href="uploads/<?= $f['name'] ?>" class="btn-dl" onclick="handleDownload(this)">
                        <span>DOWNLOAD</span>
                    </a>
                    <a href="?delete=<?= $f['name'] ?>" class="btn-del" onclick="return confirm('Delete this record?')">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#e03131" stroke-width="2.5"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
    // 1. CLEAN URL ON RELOAD (Fixes sticky alerts)
    if (window.history.replaceState) {
        const url = new URL(window.location.href);
        url.searchParams.delete('status');
        url.searchParams.delete('name');
        window.history.replaceState({path: url.href}, '', url.href);
    }

    // 2. AUTO-HIDE ALERT
    const alertBox = document.getElementById('alertBox');
    if (alertBox) {
        setTimeout(() => {
            alertBox.style.opacity = '0';
            setTimeout(() => alertBox.remove(), 500);
        }, 3000);
    }

    // 3. UPLOAD LOADING STATE
    document.getElementById('uploadForm').onsubmit = function() {
        const btn = document.getElementById('submitBtn');
        const spinner = document.getElementById('uploadSpinner');
        const btnText = document.getElementById('btnText');
        
        btn.disabled = true;
        spinner.style.display = 'block';
        btnText.innerText = 'Uploading to Cloud...';
    };

    function handleFileSelect(input) {
        if (input.files.length > 0) {
            document.getElementById('file-name').textContent = '📄 ' + input.files[0].name;
        }
    }

    // 4. DOWNLOAD LOADING STATE
    function handleDownload(link) {
        const originalText = link.innerHTML;
        link.innerHTML = '<span>PREPARING...</span>';
        link.style.pointerEvents = 'none';
        link.style.opacity = '0.7';

        // Reset button after 3 seconds (enough time for download to trigger)
        setTimeout(() => {
            link.innerHTML = originalText;
            link.style.pointerEvents = 'auto';
            link.style.opacity = '1';
        }, 3000);
    }
</script>

</body>
</html>