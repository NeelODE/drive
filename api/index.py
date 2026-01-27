import os
import json
import shutil
from pathlib import Path
from flask import Flask, request, jsonify, send_file, Response
from werkzeug.utils import secure_filename
import mimetypes
import logging

app = Flask(__name__)
app.config['MAX_CONTENT_LENGTH'] = 100 * 1024 * 1024  # 100MB max file size

# Configuration
BASE_DIR = Path('/tmp/my_files')  # Vercel uses /tmp for writable storage
BASE_DIR.mkdir(exist_ok=True, parents=True)

MAX_TEXT_VIEW_SIZE = 5 * 1024 * 1024  # 5 MB

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


def sanitize_path(path_str, base):
    """
    Sanitizes a user-provided path relative to a base directory.
    Prevents path traversal attacks.
    """
    if not path_str:
        return base
    
    # Remove null bytes
    if '\0' in path_str:
        return None
    
    # Normalize path
    path_str = path_str.strip().strip('/\\')
    
    if not path_str or path_str == '.':
        return base
    
    if path_str == '..':
        return None
    
    # Build absolute path
    try:
        full_path = (base / path_str).resolve()
        
        # Check if within base directory
        if base.resolve() in full_path.parents or full_path == base.resolve():
            return full_path
        
        logger.warning(f"Path traversal attempt: {path_str}")
        return None
    except Exception as e:
        logger.error(f"Path sanitization error: {e}")
        return None


def get_relative_path(full_path, base):
    """Gets the relative path from base directory."""
    try:
        return str(full_path.relative_to(base))
    except ValueError:
        return None


def format_bytes(bytes_val):
    """Formats bytes into human-readable string."""
    if bytes_val is None or bytes_val < 0:
        return ''
    if bytes_val == 0:
        return '0 Bytes'
    
    suffixes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB']
    i = 0
    while bytes_val >= 1024 and i < len(suffixes) - 1:
        bytes_val /= 1024.0
        i += 1
    
    return f"{bytes_val:.2f} {suffixes[i]}"


def recursive_copy(source, dest):
    """Recursively copies a file or directory."""
    try:
        if source.is_file():
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, dest)
        else:
            shutil.copytree(source, dest, dirs_exist_ok=True)
        return True
    except Exception as e:
        logger.error(f"Copy error: {e}")
        return False


def get_directory_size(path):
    """Calculate total size of directory recursively."""
    total = 0
    try:
        for entry in path.rglob('*'):
            if entry.is_file():
                total += entry.stat().st_size
    except Exception as e:
        logger.error(f"Error calculating directory size: {e}")
    return total


@app.route('/')
def index():
    """Serves the main HTML interface."""
    html_content = '''<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Manager</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; background: #f5f5f5; padding: 20px; line-height: 1.6; }
        .container { max-width: 1200px; margin: 0 auto; background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; }
        .header h1 { font-size: 28px; margin-bottom: 10px; }
        .breadcrumb { display: flex; align-items: center; gap: 8px; margin-top: 15px; flex-wrap: wrap; }
        .breadcrumb a { color: rgba(255,255,255,0.9); text-decoration: none; padding: 5px 10px; border-radius: 5px; transition: background 0.2s; }
        .breadcrumb a:hover { background: rgba(255,255,255,0.1); }
        .breadcrumb span { color: rgba(255,255,255,0.6); }
        .main-content { padding: 30px; }
        .actions { display: flex; gap: 15px; margin-bottom: 25px; flex-wrap: wrap; }
        .actions form { flex: 1; min-width: 250px; display: flex; gap: 10px; }
        .actions input[type="text"], .actions input[type="file"] { flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; }
        .actions input[type="file"] { padding: 8px; }
        .btn { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.2s; }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5568d3; }
        .btn-secondary { background: #48bb78; color: white; }
        .btn-secondary:hover { background: #38a169; }
        .btn-danger { background: #f56565; color: white; }
        .btn-danger:hover { background: #e53e3e; }
        .btn-info { background: #4299e1; color: white; }
        .btn-info:hover { background: #3182ce; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .selection-bar { background: #edf2f7; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: none; }
        .selection-bar.active { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
        .selection-info { flex: 1; font-weight: 500; color: #2d3748; }
        .clipboard-status { background: #bee3f8; padding: 10px 15px; border-radius: 5px; margin-bottom: 20px; display: none; align-items: center; gap: 10px; }
        .clipboard-status.active { display: flex; }
        .status { padding: 12px 20px; border-radius: 5px; margin-bottom: 20px; display: none; }
        .status.success { background: #c6f6d5; color: #22543d; display: block; }
        .status.error { background: #fed7d7; color: #742a2a; display: block; }
        .status.info { background: #bee3f8; color: #2c5282; display: block; }
        .file-list { list-style: none; }
        .file-item { display: flex; align-items: center; padding: 15px; border-bottom: 1px solid #eee; transition: background 0.2s; cursor: pointer; }
        .file-item:hover { background: #f7fafc; }
        .file-item input[type="checkbox"] { margin-right: 15px; width: 18px; height: 18px; cursor: pointer; }
        .file-icon { font-size: 24px; margin-right: 15px; }
        .file-info { flex: 1; }
        .file-name { font-weight: 500; color: #2d3748; margin-bottom: 5px; }
        .file-meta { font-size: 13px; color: #718096; }
        .file-actions { display: flex; gap: 8px; }
        .file-actions button { padding: 6px 12px; font-size: 13px; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; border-radius: 10px; max-width: 90%; max-height: 90%; overflow: auto; position: relative; }
        .modal-header { padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        .modal-body { padding: 20px; }
        .modal-close { background: #f56565; color: white; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer; }
        .progress-bar { width: 100%; height: 8px; background: #eee; border-radius: 4px; overflow: hidden; margin-top: 10px; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #667eea, #764ba2); transition: width 0.3s; }
        .drag-over-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(102, 126, 234, 0.9); display: flex; align-items: center; justify-content: center; color: white; font-size: 32px; font-weight: bold; z-index: 999; pointer-events: none; }
        .empty-state { text-align: center; padding: 60px 20px; color: #718096; }
        .empty-state svg { width: 100px; height: 100px; margin-bottom: 20px; opacity: 0.5; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📁 File Manager</h1>
            <div class="breadcrumb" id="breadcrumb"></div>
        </div>
        
        <div class="main-content">
            <div class="actions">
                <form id="uploadForm">
                    <input type="file" id="filesToUpload" name="files[]" multiple>
                    <button type="submit" class="btn btn-primary">Upload</button>
                </form>
                <form id="createFolderForm">
                    <input type="text" id="folder_name" name="folder_name" placeholder="New folder name" required pattern="[^<>:\"/\\|?*]+" title="Avoid special characters">
                    <button type="submit" class="btn btn-secondary">Create Folder</button>
                </form>
            </div>
            
            <div id="status" class="status"></div>
            
            <div id="clipboardStatus" class="clipboard-status"></div>
            
            <div id="selectionBar" class="selection-bar">
                <span class="selection-info" id="selectionInfo"></span>
                <button class="btn btn-info" id="batchCopyBtn">Copy Selected</button>
                <button class="btn btn-info" id="batchCutBtn">Cut Selected</button>
                <button class="btn btn-danger" id="batchDeleteBtn">Delete Selected</button>
            </div>
            
            <div style="margin-bottom: 15px;">
                <label><input type="checkbox" id="selectAll"> Select All</label>
                <button class="btn btn-info" id="pasteButton" style="margin-left: 15px; display: none;">Paste Here</button>
            </div>
            
            <ul id="fileList" class="file-list"></ul>
            
            <div class="empty-state" id="emptyState" style="display: none;">
                <svg fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"></path></svg>
                <p>No files or folders here yet</p>
            </div>
        </div>
    </div>
    
    <div id="fileViewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">File Viewer</h2>
                <button class="modal-close" id="modalClose">Close</button>
            </div>
            <div class="modal-body" id="modalBody"></div>
        </div>
    </div>
    
    <script>
        let currentDirectory = '';
        let clipboard = null;
        
        const fileList = document.getElementById('fileList');
        const breadcrumb = document.getElementById('breadcrumb');
        const statusDiv = document.getElementById('status');
        const uploadForm = document.getElementById('uploadForm');
        const createFolderForm = document.getElementById('createFolderForm');
        const fileViewModal = document.getElementById('fileViewModal');
        const modalBody = document.getElementById('modalBody');
        const modalTitle = document.getElementById('modalTitle');
        const modalClose = document.getElementById('modalClose');
        const pasteButton = document.getElementById('pasteButton');
        const selectAllCheckbox = document.getElementById('selectAll');
        const selectionBar = document.getElementById('selectionBar');
        const selectionInfo = document.getElementById('selectionInfo');
        const batchCopyBtn = document.getElementById('batchCopyBtn');
        const batchCutBtn = document.getElementById('batchCutBtn');
        const batchDeleteBtn = document.getElementById('batchDeleteBtn');
        const clipboardStatus = document.getElementById('clipboardStatus');
        const emptyState = document.getElementById('emptyState');
        
        function showStatus(message, type = 'info', timeout = 3000) {
            statusDiv.textContent = message;
            statusDiv.className = `status ${type}`;
            if (timeout > 0) {
                setTimeout(() => statusDiv.className = 'status', timeout);
            }
        }
        
        function updateBreadcrumb() {
            breadcrumb.innerHTML = '<a href="#" data-path="">🏠 Home</a>';
            if (currentDirectory) {
                const parts = currentDirectory.split('/');
                let path = '';
                parts.forEach((part, i) => {
                    path += (i > 0 ? '/' : '') + part;
                    breadcrumb.innerHTML += ` <span>/</span> <a href="#" data-path="${path}">${part}</a>`;
                });
            }
            breadcrumb.querySelectorAll('a').forEach(a => {
                a.addEventListener('click', (e) => {
                    e.preventDefault();
                    loadFiles(a.dataset.path);
                });
            });
        }
        
        async function loadFiles(dir = '') {
            currentDirectory = dir;
            updateBreadcrumb();
            
            try {
                const response = await fetch(`/api/list?dir=${encodeURIComponent(dir)}`);
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.message);
                }
                
                fileList.innerHTML = '';
                
                if (data.files.length === 0) {
                    emptyState.style.display = 'block';
                    fileList.style.display = 'none';
                } else {
                    emptyState.style.display = 'none';
                    fileList.style.display = 'block';
                    
                    data.files.forEach(file => {
                        const li = document.createElement('li');
                        li.className = 'file-item';
                        li.dataset.path = file.path;
                        li.dataset.name = file.name;
                        
                        const icon = file.is_dir ? '📁' : '📄';
                        
                        li.innerHTML = `
                            <input type="checkbox" class="file-selector">
                            <span class="file-icon">${icon}</span>
                            <div class="file-info">
                                ${file.is_dir ? 
                                    `<div class="file-name"><a href="#" data-path="${file.path}">${file.name}</a></div>` :
                                    `<div class="file-name">${file.name}</div>`
                                }
                                <div class="file-meta">${file.size} • ${file.modified}</div>
                            </div>
                            <div class="file-actions">
                                ${!file.is_dir ? `<button class="btn btn-info view-btn">View</button>` : ''}
                                <button class="btn btn-primary download-btn" data-size="${file.size_bytes}">Download</button>
                                <button class="btn btn-secondary copy-btn">Copy</button>
                                <button class="btn btn-secondary cut-btn">Cut</button>
                                <button class="btn btn-danger delete-btn">Delete</button>
                            </div>
                        `;
                        
                        fileList.appendChild(li);
                    });
                }
                
                updateSelectionState();
            } catch (error) {
                showStatus(`Error: ${error.message}`, 'error');
            }
        }
        
        function updateSelectionState() {
            const selected = fileList.querySelectorAll('.file-selector:checked');
            if (selected.length > 0) {
                selectionBar.classList.add('active');
                selectionInfo.textContent = `${selected.length} item(s) selected`;
            } else {
                selectionBar.classList.remove('active');
            }
            selectAllCheckbox.checked = selected.length === fileList.querySelectorAll('.file-selector').length && selected.length > 0;
        }
        
        function getSelectedItems() {
            return Array.from(fileList.querySelectorAll('.file-selector:checked')).map(cb => {
                const li = cb.closest('li');
                return { path: li.dataset.path, name: li.dataset.name };
            });
        }
        
        function updateClipboardStatus() {
            if (clipboard) {
                const count = clipboard.sourcePaths.length;
                const op = clipboard.operation === 'copy' ? 'copied' : 'cut';
                clipboardStatus.innerHTML = `${count} item(s) ${op}. <button class="btn btn-secondary" id="pasteBtn" style="margin-left: auto;">Paste Here</button>`;
                clipboardStatus.classList.add('active');
                pasteButton.style.display = 'inline-block';
                
                document.getElementById('pasteBtn').addEventListener('click', pasteItem);
            } else {
                clipboardStatus.classList.remove('active');
                pasteButton.style.display = 'none';
            }
        }
        
        async function handleFileUploads(files) {
            if (files.length === 0) return;
            
            showStatus(`Uploading ${files.length} file(s)...`, 'info', 0);
            
            for (const file of files) {
                const formData = new FormData();
                formData.append('file', file);
                formData.append('dir', currentDirectory);
                
                try {
                    const response = await fetch('/api/upload', { method: 'POST', body: formData });
                    const data = await response.json();
                    
                    if (!data.success) {
                        throw new Error(data.message);
                    }
                } catch (error) {
                    showStatus(`Upload failed: ${error.message}`, 'error');
                    return;
                }
            }
            
            showStatus('Upload completed!', 'success');
            uploadForm.reset();
            loadFiles(currentDirectory);
        }
        
        async function viewItem(path, name) {
            modalTitle.textContent = name;
            modalBody.innerHTML = '<p>Loading...</p>';
            fileViewModal.classList.add('active');
            
            try {
                const response = await fetch(`/api/view?path=${encodeURIComponent(path)}`);
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.message);
                }
                
                if (data.is_text) {
                    modalBody.innerHTML = `<pre style="white-space: pre-wrap; word-wrap: break-word;">${escapeHtml(data.content)}</pre>`;
                } else if (data.is_image) {
                    modalBody.innerHTML = `<img src="/api/download?path=${encodeURIComponent(path)}" style="max-width: 100%;">`;
                } else {
                    modalBody.innerHTML = '<p>Cannot preview this file type. Use download instead.</p>';
                }
            } catch (error) {
                modalBody.innerHTML = `<p>Error: ${error.message}</p>`;
            }
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        async function downloadFileWithProgress(path, name, size) {
            try {
                const response = await fetch(`/api/download?path=${encodeURIComponent(path)}`);
                if (!response.ok) throw new Error('Download failed');
                
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = name;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
                
                showStatus('Download started', 'success');
            } catch (error) {
                showStatus(`Download failed: ${error.message}`, 'error');
            }
        }
        
        function copyItem(path, name) {
            clipboard = { sourcePaths: [path], sourceNames: [name], operation: 'copy' };
            updateClipboardStatus();
            showStatus('Item copied', 'info');
        }
        
        function cutItem(path, name) {
            clipboard = { sourcePaths: [path], sourceNames: [name], operation: 'cut' };
            updateClipboardStatus();
            showStatus('Item cut', 'info');
        }
        
        async function pasteItem() {
            if (!clipboard) return;
            
            showStatus('Pasting...', 'info', 0);
            
            try {
                const response = await fetch('/api/paste', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        sourcePaths: clipboard.sourcePaths,
                        destDir: currentDirectory,
                        operation: clipboard.operation
                    })
                });
                
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.message);
                }
                
                showStatus(data.message, 'success');
                
                if (clipboard.operation === 'cut') {
                    clipboard = null;
                    updateClipboardStatus();
                }
                
                loadFiles(currentDirectory);
            } catch (error) {
                showStatus(`Paste failed: ${error.message}`, 'error');
            }
        }
        
        async function deleteItem(path, name) {
            if (!confirm(`Delete "${name}"? This cannot be undone.`)) return;
            
            try {
                const response = await fetch('/api/delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ paths: [path] })
                });
                
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.message);
                }
                
                showStatus(data.message, 'success');
                loadFiles(currentDirectory);
            } catch (error) {
                showStatus(`Delete failed: ${error.message}`, 'error');
            }
        }
        
        async function deleteItems(items) {
            try {
                const response = await fetch('/api/delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ paths: items.map(i => i.path) })
                });
                
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.message);
                }
                
                showStatus(data.message, 'success');
                loadFiles(currentDirectory);
            } catch (error) {
                showStatus(`Delete failed: ${error.message}`, 'error');
            }
        }
        
        function closeModal() {
            fileViewModal.classList.remove('active');
        }
        
        // Event Listeners
        uploadForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const fileInput = document.getElementById('filesToUpload');
            handleFileUploads(fileInput.files);
        });
        
        createFolderForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const folderName = document.getElementById('folder_name').value.trim();
            
            if (!folderName) {
                showStatus('Invalid folder name', 'error');
                return;
            }
            
            try {
                const response = await fetch('/api/create-folder', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ folder_name: folderName, dir: currentDirectory })
                });
                
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.message);
                }
                
                showStatus(data.message, 'success');
                createFolderForm.reset();
                loadFiles(currentDirectory);
            } catch (error) {
                showStatus(`Failed to create folder: ${error.message}`, 'error');
            }
        });
        
        modalClose.addEventListener('click', closeModal);
        fileViewModal.addEventListener('click', (e) => { if (e.target === fileViewModal) closeModal(); });
        
        fileList.addEventListener('click', e => {
            const target = e.target;
            const li = target.closest('li');
            if (!li) return;
            
            const path = li.dataset.path;
            const name = li.dataset.name;
            
            if (target.tagName === 'BUTTON') {
                e.stopPropagation();
                if (target.classList.contains('view-btn')) viewItem(path, name);
                if (target.classList.contains('download-btn')) downloadFileWithProgress(path, name, target.dataset.size);
                if (target.classList.contains('copy-btn')) copyItem(path, name);
                if (target.classList.contains('cut-btn')) cutItem(path, name);
                if (target.classList.contains('delete-btn')) deleteItem(path, name);
                return;
            }
            
            if (target.tagName === 'A') {
                e.preventDefault();
                loadFiles(target.dataset.path);
                return;
            }
            
            const checkbox = li.querySelector('.file-selector');
            if (checkbox && target.type !== 'checkbox') {
                checkbox.checked = !checkbox.checked;
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
            fileList.querySelectorAll('.file-selector').forEach(cb => cb.checked = isChecked);
            updateSelectionState();
        });
        
        batchCopyBtn.addEventListener('click', () => {
            const items = getSelectedItems();
            if (items.length > 0) {
                clipboard = { sourcePaths: items.map(i => i.path), sourceNames: items.map(i => i.name), operation: 'copy' };
                updateClipboardStatus();
                showStatus(`${items.length} item(s) copied`, 'info');
            }
        });
        
        batchCutBtn.addEventListener('click', () => {
            const items = getSelectedItems();
            if (items.length > 0) {
                clipboard = { sourcePaths: items.map(i => i.path), sourceNames: items.map(i => i.name), operation: 'cut' };
                updateClipboardStatus();
                showStatus(`${items.length} item(s) cut`, 'info');
            }
        });
        
        batchDeleteBtn.addEventListener('click', () => {
            const items = getSelectedItems();
            if (items.length > 0 && confirm(`Delete ${items.length} items? This cannot be undone.`)) {
                deleteItems(items);
            }
        });
        
        // Drag and drop
        let dragCounter = 0;
        document.body.addEventListener('dragenter', e => {
            e.preventDefault();
            dragCounter++;
            if (dragCounter === 1) {
                const overlay = document.createElement('div');
                overlay.className = 'drag-over-overlay';
                overlay.id = 'drag-overlay';
                overlay.textContent = 'Drop Files to Upload';
                document.body.appendChild(overlay);
            }
        });
        
        document.body.addEventListener('dragleave', e => {
            e.preventDefault();
            setTimeout(() => {
                dragCounter--;
                if (dragCounter === 0) {
                    document.getElementById('drag-overlay')?.remove();
                }
            }, 100);
        });
        
        document.body.addEventListener('dragover', e => e.preventDefault());
        
        document.body.addEventListener('drop', e => {
            e.preventDefault();
            dragCounter = 0;
            document.getElementById('drag-overlay')?.remove();
            if (e.dataTransfer.files.length > 0) {
                handleFileUploads(e.dataTransfer.files);
            }
        });
        
        // Initial load
        loadFiles('');
        updateClipboardStatus();
    </script>
</body>
</html>
'''
    return html_content


@app.route('/api/list')
def list_files():
    """Lists files and folders in a directory."""
    dir_param = request.args.get('dir', '')
    
    target_dir = sanitize_path(dir_param, BASE_DIR)
    if not target_dir or not target_dir.exists():
        return jsonify({'success': False, 'message': 'Invalid directory'}), 400
    
    try:
        files = []
        for entry in sorted(target_dir.iterdir(), key=lambda x: (not x.is_dir(), x.name.lower())):
            try:
                stat = entry.stat()
                if entry.is_dir():
                    size = get_directory_size(entry)
                else:
                    size = stat.st_size
                
                files.append({
                    'name': entry.name,
                    'path': get_relative_path(entry, BASE_DIR) or '',
                    'is_dir': entry.is_dir(),
                    'size': format_bytes(size),
                    'size_bytes': size,
                    'modified': Path(entry).stat().st_mtime
                })
            except Exception as e:
                logger.error(f"Error reading {entry}: {e}")
                continue
        
        return jsonify({'success': True, 'files': files})
    
    except Exception as e:
        logger.error(f"List error: {e}")
        return jsonify({'success': False, 'message': str(e)}), 500


@app.route('/api/upload', methods=['POST'])
def upload_file():
    """Handles file uploads."""
    if 'file' not in request.files:
        return jsonify({'success': False, 'message': 'No file provided'}), 400
    
    file = request.files['file']
    dir_param = request.form.get('dir', '')
    
    if file.filename == '':
        return jsonify({'success': False, 'message': 'No file selected'}), 400
    
    target_dir = sanitize_path(dir_param, BASE_DIR)
    if not target_dir:
        return jsonify({'success': False, 'message': 'Invalid directory'}), 400
    
    try:
        target_dir.mkdir(parents=True, exist_ok=True)
        filename = secure_filename(file.filename)
        filepath = target_dir / filename
        
        file.save(str(filepath))
        
        return jsonify({'success': True, 'message': f'Uploaded {filename}'})
    
    except Exception as e:
        logger.error(f"Upload error: {e}")
        return jsonify({'success': False, 'message': str(e)}), 500


@app.route('/api/create-folder', methods=['POST'])
def create_folder():
    """Creates a new folder."""
    data = request.json
    folder_name = data.get('folder_name', '').strip()
    dir_param = data.get('dir', '')
    
    if not folder_name:
        return jsonify({'success': False, 'message': 'Invalid folder name'}), 400
    
    folder_name = secure_filename(folder_name)
    target_dir = sanitize_path(dir_param, BASE_DIR)
    
    if not target_dir:
        return jsonify({'success': False, 'message': 'Invalid directory'}), 400
    
    try:
        new_folder = target_dir / folder_name
        new_folder.mkdir(parents=True, exist_ok=False)
        
        return jsonify({'success': True, 'message': f'Created folder {folder_name}'})
    
    except FileExistsError:
        return jsonify({'success': False, 'message': 'Folder already exists'}), 400
    except Exception as e:
        logger.error(f"Create folder error: {e}")
        return jsonify({'success': False, 'message': str(e)}), 500


@app.route('/api/view')
def view_file():
    """Views file content (for text files)."""
    path_param = request.args.get('path', '')
    
    target_path = sanitize_path(path_param, BASE_DIR)
    if not target_path or not target_path.exists() or not target_path.is_file():
        return jsonify({'success': False, 'message': 'Invalid file'}), 400
    
    try:
        mime_type, _ = mimetypes.guess_type(str(target_path))
        is_text = mime_type and mime_type.startswith('text/')
        is_image = mime_type and mime_type.startswith('image/')
        
        content = None
        if is_text and target_path.stat().st_size <= MAX_TEXT_VIEW_SIZE:
            with open(target_path, 'r', encoding='utf-8', errors='ignore') as f:
                content = f.read()
        
        return jsonify({
            'success': True,
            'is_text': is_text,
            'is_image': is_image,
            'content': content
        })
    
    except Exception as e:
        logger.error(f"View error: {e}")
        return jsonify({'success': False, 'message': str(e)}), 500


@app.route('/api/download')
def download_file():
    """Downloads a file."""
    path_param = request.args.get('path', '')
    
    target_path = sanitize_path(path_param, BASE_DIR)
    if not target_path or not target_path.exists():
        return jsonify({'success': False, 'message': 'File not found'}), 404
    
    try:
        if target_path.is_file():
            return send_file(str(target_path), as_attachment=True, download_name=target_path.name)
        else:
            # For directories, create a zip file
            import zipfile
            from io import BytesIO
            
            memory_file = BytesIO()
            with zipfile.ZipFile(memory_file, 'w', zipfile.ZIP_DEFLATED) as zf:
                for file in target_path.rglob('*'):
                    if file.is_file():
                        arcname = file.relative_to(target_path)
                        zf.write(file, arcname)
            
            memory_file.seek(0)
            return send_file(memory_file, as_attachment=True, download_name=f'{target_path.name}.zip', mimetype='application/zip')
    
    except Exception as e:
        logger.error(f"Download error: {e}")
        return jsonify({'success': False, 'message': str(e)}), 500


@app.route('/api/delete', methods=['POST'])
def delete_items():
    """Deletes files or folders."""
    data = request.json
    paths = data.get('paths', [])
    
    if not paths:
        return jsonify({'success': False, 'message': 'No items to delete'}), 400
    
    try:
        deleted = 0
        for path_param in paths:
            target_path = sanitize_path(path_param, BASE_DIR)
            if target_path and target_path.exists():
                if target_path.is_dir():
                    shutil.rmtree(target_path)
                else:
                    target_path.unlink()
                deleted += 1
        
        return jsonify({'success': True, 'message': f'Deleted {deleted} item(s)'})
    
    except Exception as e:
        logger.error(f"Delete error: {e}")
        return jsonify({'success': False, 'message': str(e)}), 500


@app.route('/api/paste', methods=['POST'])
def paste_items():
    """Pastes (copies or moves) files/folders."""
    data = request.json
    source_paths = data.get('sourcePaths', [])
    dest_dir_param = data.get('destDir', '')
    operation = data.get('operation', 'copy')
    
    if not source_paths:
        return jsonify({'success': False, 'message': 'No items to paste'}), 400
    
    dest_dir = sanitize_path(dest_dir_param, BASE_DIR)
    if not dest_dir:
        return jsonify({'success': False, 'message': 'Invalid destination'}), 400
    
    try:
        dest_dir.mkdir(parents=True, exist_ok=True)
        pasted = 0
        
        for source_param in source_paths:
            source_path = sanitize_path(source_param, BASE_DIR)
            if not source_path or not source_path.exists():
                continue
            
            dest_path = dest_dir / source_path.name
            
            # Handle name conflicts
            if dest_path.exists():
                base = dest_path.stem
                ext = dest_path.suffix
                counter = 1
                while dest_path.exists():
                    dest_path = dest_dir / f"{base}_{counter}{ext}"
                    counter += 1
            
            if operation == 'copy':
                if recursive_copy(source_path, dest_path):
                    pasted += 1
            else:  # cut (move)
                shutil.move(str(source_path), str(dest_path))
                pasted += 1
        
        action = 'copied' if operation == 'copy' else 'moved'
        return jsonify({'success': True, 'message': f'{action.capitalize()} {pasted} item(s)'})
    
    except Exception as e:
        logger.error(f"Paste error: {e}")
        return jsonify({'success': False, 'message': str(e)}), 500


# Vercel serverless function handler
app = app