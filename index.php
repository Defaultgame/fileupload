<?php
session_start();
require_once __DIR__ . '/config.php';

// Check if user is authenticated
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Transfer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: Arial, sans-serif;
        }
        .upload-container {
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .drag-area {
            border: 2px dashed #ddd;
            border-radius: 5px;
            padding: 30px;
            text-align: center;
            margin: 20px 0;
            background: #f8f9fa;
        }
        .drag-area.active {
            border-color: #007bff;
            background: #e3f2fd;
        }
        .file-list {
            margin: 20px 0;
        }
        .file-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px;
            background: #f8f9fa;
            margin: 5px 0;
            border-radius: 4px;
        }
        .progress {
            display: none;
            margin: 20px 0;
        }
        .loading {
            display: none;
            text-align: center;
            margin: 20px 0;
        }
        .loading-spinner {
            width: 40px;
            height: 40px;
            margin: 10px auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="upload-container">
            <h2 class="text-center mb-4">File Transfer</h2>
            
            <form id="uploadForm" enctype="multipart/form-data">
                <div class="mb-3">
                    <label for="recipient_email" class="form-label">Recipient Email</label>
                    <input type="email" class="form-control" id="recipient_email" name="recipient_email" required>
                </div>

                <div class="drag-area" id="dragArea">
                    <p>Drag & Drop files here or</p>
                    <input type="file" id="fileInput" name="files[]" multiple style="display: none">
                    <button type="button" class="btn btn-primary" onclick="document.getElementById('fileInput').click()">
                        Browse Files
                    </button>
                </div>

                <div class="file-list" id="fileList"></div>

                <div class="progress">
                    <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                </div>

                <div class="loading">
                    <div class="spinner-border loading-spinner" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p>Processing files...</p>
                </div>

                <button type="submit" class="btn btn-success w-100">Upload Files</button>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // File handling functions
        const dragArea = document.getElementById('dragArea');
        const fileInput = document.getElementById('fileInput');
        const fileList = document.getElementById('fileList');
        const uploadForm = document.getElementById('uploadForm');
        const progressBar = document.querySelector('.progress-bar');
        const progress = document.querySelector('.progress');
        const loading = document.querySelector('.loading');

        // Prevent default drag behaviors
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dragArea.addEventListener(eventName, preventDefaults, false);
            document.body.addEventListener(eventName, preventDefaults, false);
        });

        // Highlight drop zone when item is dragged over it
        ['dragenter', 'dragover'].forEach(eventName => {
            dragArea.addEventListener(eventName, highlight, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dragArea.addEventListener(eventName, unhighlight, false);
        });

        // Handle dropped files
        dragArea.addEventListener('drop', handleDrop, false);

        // Handle selected files
        fileInput.addEventListener('change', handleFiles);

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        function highlight(e) {
            dragArea.classList.add('active');
        }

        function unhighlight(e) {
            dragArea.classList.remove('active');
        }

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            handleFiles({ target: { files: files } });
        }

        function handleFiles(e) {
            const files = [...e.target.files];
            updateFileList(files);
        }

        function updateFileList(files) {
            fileList.innerHTML = '';
            files.forEach(file => {
                const fileItem = document.createElement('div');
                fileItem.className = 'file-item';
                fileItem.innerHTML = `
                    <span>${file.name}</span>
                    <small>${formatFileSize(file.size)}</small>
                `;
                fileList.appendChild(fileItem);
            });
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Form submission
        uploadForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            progress.style.display = 'block';
            loading.style.display = 'block';

            const formData = new FormData(this);

            try {
                const response = await fetch('upload.php', {
                    method: 'POST',
                    body: formData
                });

                if (response.status >= 200 && response.status < 300) {
                    response.json().then(result => {
                        if (result.success) {
                            alert('Files uploaded and shared successfully!');
                            this.reset();
                            fileList.innerHTML = '';
                        } else {
                            throw new Error(result.error || 'Upload failed');
                        }
                    });
                } else {
                    throw new Error('Network response was not ok');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error: ' + error.message);
            } finally {
                submitButton.disabled = false;
                progress.style.display = 'none';
                loading.style.display = 'none';
                progressBar.style.width = '0%';
            }
        });
    </script>
</body>
</html>
