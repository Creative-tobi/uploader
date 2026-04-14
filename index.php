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
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); padding: 15px; color: #1c1e21; margin: 0; }
        .container { max-width: 850px; margin: 20px auto; background: #ffffff; padding: 25px; border-radius: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
        h1 { color: var(--primary); text-align: center; font-size: 1.8rem; font-weight: 800; margin-bottom: 25px; }
        
        .message { padding: 15px; border-radius: 12px; margin-bottom: 25px; text-align: center; font-weight: 700; border: 2px solid transparent; animation: pop 0.3s ease; transition: 0.5s; font-size: 0.9rem; }
        .success { background: #e6fcf5; color: #099268; border-color: #c3fae8; }
        .error { background: #fff5f5; color: var(--danger); border-color: #ffe3e3; }

        .upload-area { background: #f8faff; border: 3px dashed #cbd5e0; padding: 30px; border-radius: 20px; text-align: center; margin-bottom: 30px; }
        .custom-label { background: #4b5563; color: white; padding: 12px 20px; border-radius: 10px; cursor: pointer; font-weight: 600; display: inline-block; font-size: 0.9rem; }
        
        .btn-push { background: var(--primary); color: white; padding: 16px; border: none; border-radius: 12px; cursor: pointer; font-size: 1rem; margin-top: 20px; font-weight: 800; width: 100%; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 10px; }
        .btn-push:disabled { background: #a5c7ff; cursor: not-allowed; }

        /* Responsive Table Wrapper */
        .table-wrapper { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 15px; }
        table { width: 100%; border-collapse: separate; border-spacing: 0 8px; min-width: 600px; /* Forces scroll on small mobile */ }
        
        th { padding: 12px; text-align: left; color: #64748b; font-size: 0.75rem; text-transform: uppercase; white-space: nowrap; }
        td { padding: 15px; background: #ffffff; border-top: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
        tr td:first-child { border-left: 1px solid #f1f5f9; border-radius: 15px 0 0 15px; font-weight: 800; color: #adb5bd; text-align: center; }
        tr td:last-child { border-right: 1px solid #f1f5f9; border-radius: 0 15px 15px 0; }

        .btn-dl { background: var(--success); color: white; text-decoration: none; padding: 8px 14px; border-radius: 8px; font-weight: 700; font-size: 0.75rem; display: inline-flex; align-items: center; white-space: nowrap; }
        .btn-del { background: #fff1f0; border: 2px solid #ffa39e; padding: 8px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; }

        .spinner { width: 18px; height: 18px; border: 3px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 1s infinite; display: none; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes pop { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }

        @media (max-width: 600px) {
            .container { padding: 15px; }
            h1 { font-size: 1.5rem; }
            .upload-area { padding: 20px; }
        }
    </style>
</head>
<body>

<div class="container">
    <div style="text-align:center;">
        <img src="rcf1.jpg" style="width:100px; border-radius:50%; border:4px solid #fff; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
    </div>
    <h1>RCF ACADEMIC PORTAL</h1>

    <?php if ($message): ?>
        <div id="alertBox" class="message <?= $messageType ?>"><?= $message ?></div>
    <?php endif; ?>

    <div class="upload-area">
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <input type="file" name="file" id="file-input" hidden required onchange="handleFileSelect(this)">
            <label for="file-input" class="custom-label">📂 Select Academic Resource</label>
            <span id="file-name" style="display:block; margin-top:15px; font-weight:800; color:var(--primary); font-size:0.9rem;">No file selected</span>
            
            <button type="submit" class="btn-push" id="submitBtn">
                <div class="spinner" id="uploadSpinner"></div>
                <span id="btnText">Upload Academic Resources</span>
            </button>
        </form>
    </div>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th width="40">S/N</th><th>RESOURCE DETAILS</th><th width="90">SIZE</th><th width="150" style="text-align:right;">ACTIONS</th></tr>
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
                    <td style="display:flex; gap:8px; justify-content:flex-end;">
                        <a href="uploads/<?= $f['name'] ?>" class="btn-dl" onclick="handleDownload(this)">
                            <span>DOWNLOAD</span>
                        </a>
                        <a href="?delete=<?= $f['name'] ?>" class="btn-del" onclick="return confirm('Delete this record?')">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#e03131" stroke-width="2.5"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    if (window.history.replaceState) {
        const url = new URL(window.location.href);
        url.searchParams.delete('status');
        url.searchParams.delete('name');
        window.history.replaceState({path: url.href}, '', url.href);
    }

    const alertBox = document.getElementById('alertBox');
    if (alertBox) {
        setTimeout(() => {
            alertBox.style.opacity = '0';
            setTimeout(() => alertBox.remove(), 500);
        }, 3000);
    }

    document.getElementById('uploadForm').onsubmit = function() {
        document.getElementById('submitBtn').disabled = true;
        document.getElementById('uploadSpinner').style.display = 'block';
        document.getElementById('btnText').innerText = 'Uploading...';
    };

    function handleFileSelect(input) {
        if (input.files.length > 0) {
            document.getElementById('file-name').textContent = '📄 ' + input.files[0].name;
        }
    }

    function handleDownload(link) {
        const originalText = link.innerHTML;
        link.innerHTML = '<span>WAIT...</span>';
        link.style.pointerEvents = 'none';
        setTimeout(() => {
            link.innerHTML = originalText;
            link.style.pointerEvents = 'auto';
        }, 3000);
    }
</script>

</body>
</html>