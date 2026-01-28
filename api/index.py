import os
import vercel_blob
from flask import Flask, request, jsonify
from werkzeug.utils import secure_filename
import logging

app = Flask(__name__)
app.config['MAX_CONTENT_LENGTH'] = 100 * 1024 * 1024  # 100MB

BLOB_READ_WRITE_TOKEN = os.environ.get('BLOB_READ_WRITE_TOKEN')

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


def sanitize_path(path_str):
    """Sanitizes path to prevent traversal."""
    if not path_str:
        return ''
    if '\0' in path_str:
        return None
    path_str = path_str.strip().strip('/\\')
    if path_str == '..':
        return None
    parts = path_str.split('/')
    normalized = []
    for part in parts:
        if part == '' or part == '.':
            continue
        if part == '..':
            if normalized:
                normalized.pop()
            else:
                return None
        else:
            normalized.append(part)
    return '/'.join(normalized)


def format_bytes(bytes_val):
    """Format bytes to human readable."""
    if bytes_val is None or bytes_val < 0:
        return ''
    if bytes_val == 0:
        return '0 Bytes'
    suffixes = ['Bytes', 'KB', 'MB', 'GB', 'TB']
    i = 0
    while bytes_val >= 1024 and i < len(suffixes) - 1:
        bytes_val /= 1024.0
        i += 1
    return f"{bytes_val:.2f} {suffixes[i]}"


def parse_blob_files(blobs, current_dir=''):
    """Parse blobs into file/folder structure."""
    files = []
    folders = set()
    current_dir = current_dir.strip('/')
    
    for blob in blobs:
        pathname = blob.get('pathname', '')
        if pathname.startswith('files/'):
            pathname = pathname[6:]
        
        if current_dir:
            if not pathname.startswith(current_dir + '/'):
                continue
            relative = pathname[len(current_dir) + 1:]
        else:
            relative = pathname
        
        parts = relative.split('/')
        if parts[-1] == '.keep':
            continue
        
        if len(parts) == 1:
            files.append({
                'name': parts[0],
                'path': pathname,
                'is_dir': False,
                'size': format_bytes(blob.get('size', 0)),
                'size_bytes': blob.get('size', 0),
                'modified': blob.get('uploadedAt', ''),
                'url': blob.get('url', '')
            })
        else:
            folder_name = parts[0]
            if folder_name not in folders:
                folders.add(folder_name)
                folder_path = f"{current_dir}/{folder_name}" if current_dir else folder_name
                files.append({
                    'name': folder_name,
                    'path': folder_path,
                    'is_dir': True,
                    'size': 'Folder',
                    'size_bytes': 0,
                    'modified': '',
                    'url': ''
                })
    
    files.sort(key=lambda x: (not x['is_dir'], x['name'].lower()))
    return files


@app.route('/')
def index():
    return open(__file__).read().split('"""HTML"""')[1].split('"""ENDHTML"""')[0]

"""HTML"""
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>File Manager</title><style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f5f5f5;padding:20px}.container{max-width:1200px;margin:0 auto;background:#fff;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.1)}.header{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:30px;border-radius:10px 10px 0 0}.header h1{font-size:28px}.badge{display:inline-block;padding:4px 8px;background:rgba(255,255,255,.2);border-radius:4px;font-size:11px;margin-left:10px}.breadcrumb{display:flex;gap:8px;margin-top:15px;flex-wrap:wrap}.breadcrumb a{color:rgba(255,255,255,.9);text-decoration:none;padding:5px 10px;border-radius:5px}.breadcrumb a:hover{background:rgba(255,255,255,.1)}.main-content{padding:30px}.actions{display:flex;gap:15px;margin-bottom:25px;flex-wrap:wrap}.actions form{flex:1;display:flex;gap:10px}.actions input{flex:1;padding:10px;border:1px solid #ddd;border-radius:5px}.btn{padding:10px 20px;border:none;border-radius:5px;cursor:pointer;font-weight:500;transition:.2s}.btn-primary{background:#667eea;color:#fff}.btn-secondary{background:#48bb78;color:#fff}.btn-danger{background:#f56565;color:#fff}.btn:hover{opacity:.9}.status{padding:12px 20px;border-radius:5px;margin-bottom:20px;display:none}.status.success{background:#c6f6d5;color:#22543d;display:block}.status.error{background:#fed7d7;color:#742a2a;display:block}.status.info{background:#bee3f8;color:#2c5282;display:block}.file-list{list-style:none}.file-item{display:flex;align-items:center;padding:15px;border-bottom:1px solid #eee}.file-item:hover{background:#f7fafc}.file-icon{font-size:24px;margin-right:15px}.file-info{flex:1}.file-name{font-weight:500;margin-bottom:5px}.file-meta{font-size:13px;color:#718096}.file-actions{display:flex;gap:8px}.file-actions .btn{padding:6px 12px;font-size:13px}.empty-state{text-align:center;padding:60px;color:#718096}</style></head><body><div class="container"><div class="header"><h1>📁 File Manager <span class="badge">Vercel Blob</span></h1><div class="breadcrumb" id="breadcrumb"></div></div><div class="main-content"><div class="actions"><form id="uploadForm"><input type="file" id="filesToUpload" multiple><button type="submit" class="btn btn-primary">Upload</button></form><form id="createFolderForm"><input type="text" id="folder_name" placeholder="New folder name" required><button type="submit" class="btn btn-secondary">Create Folder</button></form></div><div id="status" class="status"></div><ul id="fileList" class="file-list"></ul><div class="empty-state" id="emptyState" style="display:none">No files yet</div></div></div><script>let currentDirectory='';function showStatus(msg,type,timeout=3000){const status=document.getElementById('status');status.textContent=msg;status.className=`status ${type}`;if(timeout>0)setTimeout(()=>status.className='status',timeout)}function updateBreadcrumb(){const breadcrumb=document.getElementById('breadcrumb');breadcrumb.innerHTML='<a href="#" data-path="">🏠 Home</a>';if(currentDirectory){const parts=currentDirectory.split('/');let path='';parts.forEach((part,i)=>{path+=(i>0?'/':'')+part;breadcrumb.innerHTML+=` / <a href="#" data-path="${path}">${part}</a>`})}breadcrumb.querySelectorAll('a').forEach(a=>{a.onclick=(e)=>{e.preventDefault();loadFiles(a.dataset.path)}})}async function loadFiles(dir=''){currentDirectory=dir;updateBreadcrumb();try{const res=await fetch(`/api/list?dir=${encodeURIComponent(dir)}`);const data=await res.json();if(!data.success)throw new Error(data.message);const fileList=document.getElementById('fileList');const emptyState=document.getElementById('emptyState');fileList.innerHTML='';if(data.files.length===0){emptyState.style.display='block';fileList.style.display='none'}else{emptyState.style.display='none';fileList.style.display='block';data.files.forEach(file=>{const li=document.createElement('li');li.className='file-item';li.innerHTML=`<span class="file-icon">${file.is_dir?'📁':'📄'}</span><div class="file-info">${file.is_dir?`<div class="file-name"><a href="#" onclick="event.preventDefault(); loadFiles('${file.path}')">${file.name}</a></div>`:`<div class="file-name">${file.name}</div>`}<div class="file-meta">${file.size}${file.modified?' • '+new Date(file.modified).toLocaleString():''}</div></div><div class="file-actions">${!file.is_dir?`<a href="${file.url}" download="${file.name}" class="btn btn-primary">Download</a>`:''}<button class="btn btn-danger" onclick="deleteItem('${file.path}','${file.name}','${file.url}')">Delete</button></div>`;fileList.appendChild(li)})}}catch(error){showStatus(`Error: ${error.message}`,'error')}}document.getElementById('uploadForm').onsubmit=async(e)=>{e.preventDefault();const files=document.getElementById('filesToUpload').files;if(files.length===0)return;showStatus(`Uploading ${files.length} file(s)...`,'info',0);for(const file of files){const formData=new FormData();formData.append('file',file);formData.append('dir',currentDirectory);try{const res=await fetch('/api/upload',{method:'POST',body:formData});const data=await res.json();if(!data.success)throw new Error(data.message)}catch(error){showStatus(`Upload failed: ${error.message}`,'error');return}}showStatus('Upload completed!','success');e.target.reset();loadFiles(currentDirectory)};document.getElementById('createFolderForm').onsubmit=async(e)=>{e.preventDefault();const folderName=document.getElementById('folder_name').value.trim();if(!folderName)return;try{const res=await fetch('/api/create-folder',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({folder_name:folderName,dir:currentDirectory})});const data=await res.json();if(!data.success)throw new Error(data.message);showStatus(data.message,'success');e.target.reset();loadFiles(currentDirectory)}catch(error){showStatus(`Failed: ${error.message}`,'error')}};async function deleteItem(path,name,url){if(!confirm(`Delete "${name}"?`))return;try{const res=await fetch('/api/delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({paths:[path],urls:[url]})});const data=await res.json();if(!data.success)throw new Error(data.message);showStatus(data.message,'success');loadFiles(currentDirectory)}catch(error){showStatus(`Delete failed: ${error.message}`,'error')}}loadFiles('')</script></body></html>
"""ENDHTML"""


@app.route('/api/list')
def list_files_route():
    if not BLOB_READ_WRITE_TOKEN:
        return jsonify({'success': False, 'message': 'Token not set'}), 500
    
    dir_param = request.args.get('dir', '')
    sanitized_dir = sanitize_path(dir_param)
    if sanitized_dir is None:
        return jsonify({'success': False, 'message': 'Invalid directory'}), 400
    
    try:
        result = vercel_blob.list({'prefix': 'files/'})
        blobs = result.get('blobs', [])
        files = parse_blob_files(blobs, sanitized_dir)
        return jsonify({'success': True, 'files': files})
    except Exception as e:
        logger.error(f"List error: {e}")
        return jsonify({'success': False, 'message': str(e)}), 500


@app.route('/api/upload', methods=['POST'])
def upload_file_route():
    if not BLOB_READ_WRITE_TOKEN:
        return jsonify({'success': False, 'message': 'Token not set'}), 500
    
    if 'file' not in request.files:
        return jsonify({'success': False, 'message': 'No file'}), 400
    
    file = request.files['file']
    dir_param = request.form.get('dir', '')
    
    if file.filename == '':
        return jsonify({'success': False, 'message': 'No file selected'}), 400
    
    sanitized_dir = sanitize_path(dir_param)
    if sanitized_dir is None:
        return jsonify({'success': False, 'message': 'Invalid directory'}), 400
    
    try:
        filename = secure_filename(file.filename)
        blob_path = f"files/{sanitized_dir}/{filename}" if sanitized_dir else f"files/{filename}"
        result = vercel_blob.put(blob_path, file.read())
        return jsonify({'success': True, 'message': f'Uploaded {filename}', 'url': result.get('url')})
    except Exception as e:
        logger.error(f"Upload error: {e}")
        return jsonify({'success': False, 'message': str(e)}), 500


@app.route('/api/create-folder', methods=['POST'])
def create_folder_route():
    if not BLOB_READ_WRITE_TOKEN:
        return jsonify({'success': False, 'message': 'Token not set'}), 500
    
    data = request.json
    folder_name = secure_filename(data.get('folder_name', '').strip())
    dir_param = data.get('dir', '')
    
    if not folder_name:
        return jsonify({'success': False, 'message': 'Invalid folder name'}), 400
    
    sanitized_dir = sanitize_path(dir_param)
    if sanitized_dir is None:
        return jsonify({'success': False, 'message': 'Invalid directory'}), 400
    
    try:
        blob_path = f"files/{sanitized_dir}/{folder_name}/.keep" if sanitized_dir else f"files/{folder_name}/.keep"
        vercel_blob.put(blob_path, b'')
        return jsonify({'success': True, 'message': f'Created folder {folder_name}'})
    except Exception as e:
        logger.error(f"Error: {e}")
        return jsonify({'success': False, 'message': str(e)}), 500


@app.route('/api/delete', methods=['POST'])
def delete_items_route():
    if not BLOB_READ_WRITE_TOKEN:
        return jsonify({'success': False, 'message': 'Token not set'}), 500
    
    data = request.json
    paths = data.get('paths', [])
    
    if not paths:
        return jsonify({'success': False, 'message': 'No items to delete'}), 400
    
    try:
        result = vercel_blob.list({'prefix': 'files/'})
        all_blobs = result.get('blobs', [])
        urls_to_delete = []
        
        for path_param in paths:
            sanitized_path = sanitize_path(path_param)
            if sanitized_path is None:
                continue
            
            blob_prefix = f"files/{sanitized_path}" if sanitized_path else "files/"
            
            for blob in all_blobs:
                pathname = blob.get('pathname', '')
                if pathname == blob_prefix or pathname.startswith(blob_prefix + '/'):
                    url = blob.get('url')
                    if url:
                        urls_to_delete.append(url)
        
        if urls_to_delete:
            vercel_blob.delete(urls_to_delete)
        
        return jsonify({'success': True, 'message': f'Deleted {len(urls_to_delete)} item(s)'})
    except Exception as e:
        logger.error(f"Delete error: {e}")
        return jsonify({'success': False, 'message': str(e)}), 500


if __name__ == '__main__':
    app.run(debug=True, port=3000)