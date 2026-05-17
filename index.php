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
$adminPassword = getenv('ADMIN_PASSWORD') ?: 'password';
$uploadDir = 'uploads/';

if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$message = '';
$messageType = '';

// 2. HANDLE SECURE DELETE
if (isset($_GET['delete'])) {
    $fileToDelete = $uploadDir . basename($_GET['delete']);
    $providedPassword = $_GET['auth'] ?? '';

    if ($providedPassword !== $adminPassword) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?status=unauthorized");
        exit();
    }

    if (file_exists($fileToDelete)) {
        unlink($fileToDelete);
        header("Location: " . $_SERVER['PHP_SELF'] . "?status=deleted");
        exit();
    }
}

// 3. PERSISTENT UPLOAD LOGIC
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    
    $dept = preg_replace('/[^a-zA-Z0-9\s-]/', '', $_POST['department'] ?? 'General');
    $level = preg_replace('/[^a-zA-Z0-9\s]/', '', $_POST['level'] ?? '100');
    $courseCode = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $_POST['course_code'] ?? 'GEN001'));
    $courseTitle = preg_replace('/[^a-zA-Z0-9\s_-]/', '', $_POST['course_title'] ?? 'Untitled Resource');
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $originalName = preg_replace('/[^a-zA-Z0-9._-]/', '', $file['name']);
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        
        $isDuplicate = false;
        if (is_dir($uploadDir)) {
            $existingFiles = array_diff(scandir($uploadDir), array('.', '..'));
            foreach ($existingFiles as $f) {
                if (strpos($f, $courseCode) !== false && strpos($f, $courseTitle) !== false) {
                    $isDuplicate = true;
                    break;
                }
            }
        }

        if ($isDuplicate) {
            $message = "⚠️ Access Denied: This exact course resource already exists.";
            $messageType = 'error';
        } else {
            $timestamp = time();
            $newFileName = "{$timestamp}||{$dept}||{$level}||{$courseCode}||{$courseTitle}.{$extension}";
            $destination = $uploadDir . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $boundary = "---------------------------" . microtime(true);
                $content = "--$boundary\r\n" .
                           "Content-Disposition: form-data; name=\"upload_preset\"\r\n\r\n" .
                           "$uploadPreset\r\n" .
                           "--$boundary\r\n" .
                           "Content-Disposition: form-data; name=\"file\"; filename=\"$newFileName\"\r\n" .
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
                    header("Location: " . $_SERVER['PHP_SELF'] . "?status=success&name=" . urlencode($courseTitle));
                    exit();
                } else {
                    $message = "Cloud Sync Failed, but local catalog saved.";
                    $messageType = 'error';
                }
            }
        }
    }
}

if (empty($message) && isset($_GET['status'])) {
    if ($_GET['status'] == 'success') {
        $message = "🚀 [" . htmlspecialchars($_GET['name'] ?? 'Resource') . "] Uploaded & Secured!";
        $messageType = 'success';
    } elseif ($_GET['status'] == 'deleted') {
        $message = "🗑️ Record Deleted Successfully.";
        $messageType = 'error';
    } elseif ($_GET['status'] == 'unauthorized') {
        $message = "❌ Action Denied: Invalid Admin Credentials.";
        $messageType = 'error';
    }
}

// 5. PARSE METADATA FROM DIRECTORY
function getUploadedFiles($dir) {
    $files = [];
    if (is_dir($dir)) {
        $list = array_diff(scandir($dir), array('.', '..'));
        foreach ($list as $file) {
            if (is_file($dir . $file)) {
                $parts = explode('||', $file);
                
                if (count($parts) >= 5) {
                    $cleanTitle = pathinfo($parts[4], PATHINFO_FILENAME);
                    $files[] = [
                        'raw_name'     => $file,
                        'timestamp'    => $parts[0],
                        'dept'         => $parts[1],
                        'level'        => $parts[2],
                        'course_code'  => $parts[3],
                        'course_title' => $cleanTitle,
                        'size'         => filesize($dir . $file),
                        'date'         => date('d M, Y | H:i', filemtime($dir . $file))
                    ];
                } else {
                    $underscorePos = strpos($file, '_');
                    $displayTitle = ($underscorePos !== false && $underscorePos < 12) ? substr($file, $underscorePos + 1) : $file;
                    $displayTitle = pathinfo($displayTitle, PATHINFO_FILENAME);

                    $files[] = [
                        'raw_name'     => $file,
                        'timestamp'    => filemtime($dir . $file),
                        'dept'         => 'General Archive',
                        'level'        => 'N/A',
                        'course_code'  => 'LEGACY',
                        'course_title' => $displayTitle,
                        'size'         => filesize($dir . $file),
                        'date'         => date('d M, Y | H:i', filemtime($dir . $file))
                    ];
                }
            }
        }
    }
    usort($files, function($a, $b) { return filemtime('uploads/'.$b['raw_name']) - filemtime('uploads/'.$a['raw_name']); });
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
        :root { --primary: #0062ff; --success: #198754; --danger: #e03131; --bg: #f0f2f5; --dark-grey: #4b5563; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); padding: 15px; color: #1c1e21; margin: 0; }
        .container { max-width: 850px; margin: 20px auto; background: #ffffff; padding: 25px; border-radius: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
        h1 { color: var(--primary); text-align: center; font-size: 1.8rem; font-weight: 800; margin-bottom: 25px; }
        
        .message { padding: 15px; border-radius: 12px; margin-bottom: 25px; text-align: center; font-weight: 700; border: 2px solid transparent; animation: pop 0.3s ease; transition: 0.5s; font-size: 0.9rem; }
        .success { background: #e6fcf5; color: #099268; border-color: #c3fae8; }
        .error { background: #fff5f5; color: var(--danger); border-color: #ffe3e3; }

        .upload-trigger-area { background: #f8faff; border: 3px dashed #cbd5e0; padding: 35px; border-radius: 20px; text-align: center; margin-bottom: 30px; transition: 0.2s; }
        .upload-trigger-area:hover { border-color: var(--primary); background: #f0f7ff; }
        .custom-label { background: var(--primary); color: white; padding: 14px 28px; border-radius: 12px; cursor: pointer; font-weight: 700; display: inline-block; font-size: 0.95rem; box-shadow: 0 4px 12px rgba(0,98,255,0.2); }
        
        /* Modal Style Settings */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 1000; opacity: 0; pointer-events: none; transition: opacity 0.3s ease; }
        .modal-overlay.active { opacity: 1; pointer-events: auto; }
        .modal-content { background: #ffffff; width: 100%; max-width: 550px; padding: 30px; border-radius: 24px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); transform: scale(0.9); transition: transform 0.3s ease; box-sizing: border-box; }
        .modal-overlay.active .modal-content { transform: scale(1); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px; }
        .modal-header h3 { margin: 0; font-size: 1.3rem; font-weight: 800; color: #1e293b; }
        .modal-close { background: none; border: none; font-size: 1.5rem; color: #94a3b8; cursor: pointer; font-weight: bold; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px; text-align: left; }
        .form-group { display: flex; flex-direction: column; }
        .form-control { padding: 11px 14px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem; font-family: inherit; }
        .form-control:focus { outline: none; border-color: var(--primary); }
        
        .btn-push { background: var(--success); color: white; padding: 16px; border: none; border-radius: 12px; cursor: pointer; font-size: 1rem; font-weight: 800; width: 100%; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 10px; }

        /* Filtering UI */
        .search-box-container { margin-bottom: 20px; }
        .search-control { width: 100%; padding: 14px 20px; border: 2px solid #cbd5e0; border-radius: 14px; font-size: 0.95rem; box-sizing: border-box; font-family: inherit; transition: 0.2s; background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%23a0aec0' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='8'%3E%3C/circle%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'%3E%3C/line%3E%3C/svg%3E") no-repeat calc(100% - 20px) center; }
        
        /* DESKTOP TABLE VIEW */
        .table-wrapper { width: 100%; border-radius: 15px; }
        table { width: 100%; border-collapse: separate; border-spacing: 0 8px; }
        th { padding: 12px; text-align: left; color: #64748b; font-size: 0.75rem; text-transform: uppercase; white-space: nowrap; }
        td { padding: 15px; background: #ffffff; border-top: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; vertical-align: middle; }
        tr td:first-child { border-left: 1px solid #f1f5f9; border-radius: 15px 0 0 15px; font-weight: 800; color: #adb5bd; text-align: center; }
        tr td:last-child { border-right: 1px solid #f1f5f9; border-radius: 0 15px 15px 0; }

        .meta-tag { display: inline-block; padding: 3px 8px; border-radius: 6px; font-size: 0.7rem; font-weight: 700; margin-right: 5px; text-transform: uppercase; }
        .tag-level { background: #e0f2fe; color: #0369a1; }
        .tag-code { background: #fef3c7; color: #92400e; }
        .tag-dept { background: #f3e8ff; color: #6b21a8; }

        .btn-dl { background: var(--success); color: white; text-decoration: none; padding: 8px 14px; border-radius: 8px; font-weight: 700; font-size: 0.75rem; display: inline-flex; align-items: center; white-space: nowrap; justify-content: center; }
        .btn-del { background: #fff1f0; border: 2px solid #ffa39e; padding: 8px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; }

        .spinner { width: 18px; height: 18px; border: 3px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 1s infinite; display: none; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes pop { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }

        /* MOBILE FLEX RESPONSIVE BREAKDOWN ENGINE */
        @media (max-width: 768px) {
            .container { padding: 15px; }
            h1 { font-size: 1.5rem; }
            .form-grid { grid-template-columns: 1fr; gap: 10px; }
            
            /* Completely transform standard table layouts into structural block cards */
            table, thead, tbody, th, td, tr { display: block; width: 100%; box-sizing: border-box; }
            thead { display: none; } /* Hide headers entirely on mobile views */
            
            table { border-spacing: 0; }
            
            .resource-row { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px !important; padding: 15px; margin-bottom: 15px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
            
            td { padding: 0 !important; border: none !important; background: transparent !important; }
            
            /* Map the S/N counter as an incremental floating badge */
            tr td:first-child { text-align: left; font-size: 0.8rem; color: #94a3b8; margin-bottom: 8px; }
            tr td:first-child::before { content: "Item #"; }
            
            /* Center resource detail rows */
            tr td:nth-child(2) { margin-bottom: 12px; }
            
            /* File size row wrapper spacing */
            tr td:nth-child(3) { font-size: 0.8rem; margin-bottom: 15px; color: #4a5568; }
            tr td:nth-child(3)::before { content: "File Size: "; font-weight: 600; color: #64748b; }
            
            /* Move action controls elegantly to the baseline profile layout */
            tr td:last-child { display: flex !important; gap: 10px; justify-content: space-between !important; width: 100%; border-top: 1px solid #f1f5f9 !important; padding-top: 12px !important; }
            
            .btn-dl { flex: 1; padding: 12px; font-size: 0.85rem; }
            .btn-del { width: 48px; height: 44px; }
            
            #noResultsRow { padding: 30px 10px !important; text-align: center; border: 1px dashed #cbd5e0 !important; border-radius: 16px; background: #fff !important; }
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

    <!-- Open Drop Upload Target -->
    <div class="upload-trigger-area">
        <input type="file" name="file" id="file-input" form="uploadForm" hidden required onchange="handleFileSelect(this)">
        <label for="file-input" class="custom-label">📂 Click here to Select File</label>
        <span id="trigger-file-name" style="display:block; margin-top:12px; font-weight:600; color:#64748b; font-size:0.9rem;">No resource attached yet</span>
    </div>

    <!-- Pop-up Dynamic Classification Panel -->
    <div id="uploadModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Resource Classification</h3>
                <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Department</label>
                        <input type="text" name="department" class="form-control" placeholder="e.g. Computer Science" required>
                    </div>
                    <div class="form-group">
                        <label>Level</label>
                        <select name="level" class="form-control" required>
                            <option value="100">100 Level</option>
                            <option value="200">200 Level</option>
                            <option value="300">300 Level</option>
                            <option value="400">400 Level</option>
                            <option value="500">500 Level</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Course Code</label>
                        <input type="text" name="course_code" class="form-control" placeholder="e.g. CSC201" required>
                    </div>
                    <div class="form-group">
                        <label>Course Title / Resource Name</label>
                        <input type="text" name="course_title" class="form-control" placeholder="e.g. Introduction to Databases" required>
                    </div>
                </div>

                <div style="background: #f8fafc; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; font-size: 0.85rem; color: var(--dark-grey); font-weight: 600;">
                    Selected: <span id="modal-selected-file" style="color: var(--primary);"></span>
                </div>
                
                <button type="submit" class="btn-push" id="submitBtn">
                    <div class="spinner" id="uploadSpinner"></div>
                    <span id="btnText">UPLOAD MATERIAL</span>
                </button>
            </form>
        </div>
    </div>

    <!-- Live Filter Field -->
    <div class="search-box-container">
        <input type="text" id="searchInput" class="search-control" placeholder="Search by Course Title, Course Code, or Level..." onkeyup="filterResources()">
    </div>

    <div class="table-wrapper">
        <table id="resourceTable">
            <thead>
                <tr><th width="40">S/N</th><th>RESOURCE DETAILS</th><th width="90">SIZE</th><th width="150" style="text-align:right;">ACTIONS</th></tr>
            </thead>
            <tbody>
                <?php $count = 1; foreach ($uploadedFiles as $f): ?>
                <tr class="resource-row" 
                    data-title="<?= htmlspecialchars(strtolower($f['course_title'])) ?>" 
                    data-code="<?= htmlspecialchars(strtolower($f['course_code'])) ?>" 
                    data-level="<?= htmlspecialchars(strtolower($f['level'])) ?>">
                    <td><?= $count++ ?></td>
                    <td>
                        <span style="font-weight:800; color:#334155; font-size: 1.05rem; display: block; line-height: 1.3;"><?= htmlspecialchars($f['course_title']) ?></span>
                        <div style="margin: 8px 0 6px 0;">
                            <span class="meta-tag tag-code"><?= htmlspecialchars($f['course_code']) ?></span>
                            <span class="meta-tag tag-level"><?= htmlspecialchars($f['level']) ?> Lvl</span>
                            <span class="meta-tag tag-dept"><?= htmlspecialchars($f['dept']) ?></span>
                        </div>
                        <small style="color:#94a3b8; font-weight: 500; display: block; margin-top: 4px;">Added: <?= $f['date'] ?></small>
                    </td>
                    <td style="font-weight:700; color:#64748b;"><?= round($f['size']/1024, 2) ?> KB</td>
                    <td>
                        <a href="uploads/<?= urlencode($f['raw_name']) ?>" class="btn-dl" download="<?= htmlspecialchars($f['course_title']) ?>" onclick="handleDownload(this)">
                            <span>DOWNLOAD</span>
                        </a>
                        <button class="btn-del" onclick="secureDelete('<?= urlencode($f['raw_name']) ?>')">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#e03131" stroke-width="2.5"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr id="noResultsRow" style="display: none;">
                    <td colspan="4" style="text-align: center; padding: 30px; color: #94a3b8; font-weight: 600;">🔍 No matching academic records found.</td>
                </tr>
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

    function handleFileSelect(input) {
        if (input.files.length > 0) {
            const fileName = input.files[0].name;
            document.getElementById('trigger-file-name').textContent = '📄 ' + fileName;
            document.getElementById('modal-selected-file').textContent = fileName;
            document.getElementById('uploadModal').classList.add('active');
        }
    }

    function closeModal() {
        document.getElementById('uploadModal').classList.remove('active');
        document.getElementById('file-input').value = "";
        document.getElementById('trigger-file-name').textContent = "No resource attached yet";
    }

    function secureDelete(fileName) {
        const password = prompt("🔒 Secure Authorization Required:\nEnter Admin Password to complete deletion:");
        if (password === null) return; 
        
        if (password.trim() === "") {
            alert("Authorization key cannot be blank.");
            return;
        }
        
        window.location.href = `?delete=${fileName}&auth=${encodeURIComponent(password)}`;
    }

    function filterResources() {
        const query = document.getElementById('searchInput').value.toLowerCase().trim();
        const rows = document.getElementsByClassName('resource-row');
        let visibleCount = 0;

        for (let i = 0; i < rows.length; i++) {
            const title = rows[i].getAttribute('data-title');
            const code = rows[i].getAttribute('data-code');
            const level = rows[i].getAttribute('data-level');

            if (title.includes(query) || code.includes(query) || level.includes(query) || `${level} level`.includes(query)) {
                rows[i].style.display = "";
                visibleCount++;
            } else {
                rows[i].style.display = "none";
            }
        }

        document.getElementById('noResultsRow').style.display = (visibleCount === 0) ? "" : "none";
    }

    document.getElementById('uploadForm').onsubmit = function() {
        document.getElementById('submitBtn').disabled = true;
        document.getElementById('uploadSpinner').style.display = 'block';
        document.getElementById('btnText').innerText = 'Archiving File...';
    };

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