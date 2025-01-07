<?php
// Prevent any output before JSON response
ob_start();

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

require_once __DIR__ . '/config.php';
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Start session
session_start();

// Constants with current timestamp and user
define('CURRENT_TIME', '2025-01-07 19:52:04');
define('CURRENT_USER', 'Defaultgame');
define('TEMP_DIR', __DIR__ . '/temp');

// Set timezone to Turkey
date_default_timezone_set('Europe/Istanbul');

// Create temp directory if it doesn't exist
if (!file_exists(TEMP_DIR)) {
    mkdir(TEMP_DIR, 0777, true);
}

// Ensure temp directory is writable
if (!is_writable(TEMP_DIR)) {
    chmod(TEMP_DIR, 0777);
}

// Function to send JSON response
function sendJsonResponse($data) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

// Function to log messages
function logMessage($message, $type = 'INFO') {
    $logEntry = sprintf(
        "[%s] [%s] [User: %s] - %s\n",
        date('Y-m-d H:i:s'),  // Use system time for logs
        $type,
        CURRENT_USER,
        $message
    );
    file_put_contents(__DIR__ . '/upload_log.txt', $logEntry, FILE_APPEND);
}

// Function to send email
function sendEmail($to, $subject, $htmlMessage) {
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'emlaktur360.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'bora@boraersavas.com';
        $mail->Password = 'zz101002';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Fix SSL verification issues
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'verify_depth' => 3,
                'ciphers' => 'ALL:@SECLEVEL=1'
            )
        );

        // Recipients
        $mail->setFrom('bora@boraersavas.com', 'File Sharing Service');
        $mail->addAddress($to);
        $mail->addReplyTo('bora@boraersavas.com', 'File Sharing Service');

        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body = $htmlMessage;
        $mail->AltBody = strip_tags(str_replace('<br>', "\n", $htmlMessage));

        $mail->send();
        logMessage("Email sent successfully to: $to", 'SUCCESS');
        return true;
    } catch (Exception $e) {
        logMessage("Mail Error: {$mail->ErrorInfo}", 'ERROR');
        return false;
    }
}

// Check authentication
if (!isset($_SESSION['access_token'])) {
    sendJsonResponse(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

try {
    // Check for files
    if (!isset($_FILES['files']) || empty($_FILES['files']['name'][0])) {
        throw new Exception('No files uploaded');
    }

    // Calculate total size and file count
    $totalSize = 0;
    $fileCount = count($_FILES['files']['tmp_name']);
    foreach ($_FILES['files']['tmp_name'] as $key => $tmpName) {
        if ($_FILES['files']['error'][$key] === UPLOAD_ERR_OK) {
            $totalSize += filesize($tmpName);
        }
    }

    // Create ZIP filename using Turkey time (not UTC)
    $zipName = date('d.m.Y.H.i.s') . '.zip';
    $zipPath = TEMP_DIR . '/' . $zipName;

    // Log ZIP creation attempt
    logMessage("Creating ZIP archive: {$zipName}", 'INFO');

    // Create ZIP archive with error checking
    $zip = new ZipArchive();
    $zipResult = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    
    if ($zipResult !== TRUE) {
        throw new Exception('Failed to create ZIP archive. Error code: ' . $zipResult);
    }

    // Add files to ZIP with error checking
    foreach ($_FILES['files']['tmp_name'] as $key => $tmpName) {
        if ($_FILES['files']['error'][$key] === UPLOAD_ERR_OK) {
            $originalName = $_FILES['files']['name'][$key];
            
            if (!file_exists($tmpName)) {
                logMessage("Temp file not found: {$tmpName}", 'ERROR');
                continue;
            }

            if (!$zip->addFile($tmpName, $originalName)) {
                logMessage("Failed to add file to ZIP: {$originalName}", 'ERROR');
            }
        } else {
            logMessage("File upload error for {$_FILES['files']['name'][$key]}: {$_FILES['files']['error'][$key]}", 'ERROR');
        }
    }

    // Close ZIP file
    if (!$zip->close()) {
        throw new Exception('Failed to close ZIP archive');
    }

    // Verify ZIP file was created
    if (!file_exists($zipPath) || filesize($zipPath) === 0) {
        throw new Exception('ZIP file creation failed or file is empty');
    }

    // Initialize Google Client
    $client = new Google_Client();
    $client->setApplicationName('File Transfer App');
    $client->setClientId(GOOGLE_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CLIENT_SECRET);
    $client->setRedirectUri(GOOGLE_REDIRECT_URI);
    $client->setAccessToken($_SESSION['access_token']);

    // Refresh token if expired
    if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            $_SESSION['access_token'] = $client->getAccessToken();
        } else {
            throw new Exception('Refresh token not available');
        }
    }

    // Upload to Google Drive
    $driveService = new Google_Service_Drive($client);
    $fileMetadata = new Google_Service_Drive_DriveFile([
        'name' => $zipName,
        'parents' => ['root']
    ]);

    // Upload file with error checking
    $file = $driveService->files->create($fileMetadata, [
        'data' => file_get_contents($zipPath),
        'mimeType' => 'application/zip',
        'uploadType' => 'multipart',
        'fields' => 'id, webViewLink'
    ]);

    // Set sharing permissions
    $driveService->permissions->create($file->getId(), new Google_Service_Drive_Permission([
        'type' => 'anyone',
        'role' => 'reader'
    ]));

    // Generate direct download link
    $shareLink = "https://drive.google.com/uc?export=download&id=" . $file->getId();

    // Send email if recipient specified
    if (isset($_POST['recipient_email']) && !empty($_POST['recipient_email'])) {
        $to = trim($_POST['recipient_email']);
        $subject = 'File shared with you';
        
        $htmlMessage = "
            <html>
            <head>
                <title>File Shared</title>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #f8f9fa; padding: 20px; border-radius: 5px; text-align: center; }
                    .content { margin: 20px 0; }
                    .link-button { 
                        display: inline-block; 
                        padding: 12px 24px; 
                        background: #007bff; 
                        color: white !important;
                        text-decoration: none; 
                        border-radius: 5px;
                        margin: 15px 0;
                    }
                    .footer { 
                        margin-top: 20px; 
                        padding-top: 20px; 
                        border-top: 1px solid #eee; 
                        font-size: 14px; 
                        color: #666;
                        text-align: center;
                    }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>You have received a shared file</h2>
                    </div>
                    <div class='content'>
                        <p>Hello,</p>
                        <p>Someone has shared a file with you. You can access it using the link below:</p>
                        <p style='text-align: center;'>
                            <a href='{$shareLink}' class='link-button'>Download File</a>
                        </p>
                        <p>File Count: {$fileCount}</p>
                        <p>Total Size: " . round($totalSize / 1048576, 2) . " MB</p>
                    </div>";

        $htmlMessage .= "
                    <div class='footer'>
                        <p>This is an automated message. Please do not reply to this email.</p>
                    </div>
                </div>
            </body>
            </html>";

        // Send email without attachments
        $mailResult = sendEmail($to, $subject, $htmlMessage);

        if (!$mailResult) {
            logMessage("Failed to send email to: $to", 'ERROR');
        }
    }

    // Clean up
    if (file_exists($zipPath)) {
        unlink($zipPath);
    }

    // Send success response
    sendJsonResponse([
        'success' => true,
        'file' => [
            'name' => $zipName,
            'shareLink' => $shareLink,
            'fileCount' => $fileCount,
            'totalSize' => round($totalSize / 1048576, 2) . ' MB'
        ]
    ]);

} catch (Exception $e) {
    logMessage("Error: " . $e->getMessage(), 'ERROR');
    sendJsonResponse([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} finally {
    // Clean up temp directory
    foreach (glob(TEMP_DIR . "/*") as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
?>
