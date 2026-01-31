'use client';
import { useState, useEffect } from 'react';

export default function FileManager() {
  const [files, setFiles] = useState([]);
  const [currentDir, setCurrentDir] = useState('');
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState({ text: '', type: '' });
  const [clipboard, setClipboard] = useState(null);
  const [selected, setSelected] = useState([]);

  useEffect(() => {
    loadFiles();
  }, [currentDir]);

  const loadFiles = async () => {
    setLoading(true);
    try {
      const res = await fetch(`/api/list?dir=${encodeURIComponent(currentDir)}`);
      const data = await res.json();
      setFiles(data.files || []);
    } catch (error) {
      showMessage('Failed to load files', 'error');
    }
    setLoading(false);
  };

  const showMessage = (text, type = 'info') => {
    setMessage({ text, type });
    setTimeout(() => setMessage({ text: '', type: '' }), 3000);
  };

  const handleUpload = async (e) => {
    e.preventDefault();
    const input = e.target.querySelector('input[type="file"]');
    const files = input.files;
    
    if (!files.length) return;

    setLoading(true);
    for (let file of files) {
      const formData = new FormData();
      formData.append('file', file);
      formData.append('dir', currentDir);
      
      try {
        await fetch('/api/upload', { method: 'POST', body: formData });
      } catch (error) {
        showMessage(`Failed to upload ${file.name}`, 'error');
        setLoading(false);
        return;
      }
    }
    
    showMessage('Upload complete!', 'success');
    input.value = '';
    loadFiles();
  };

  const handleDelete = async (path) => {
    if (!confirm('Delete this item?')) return;
    
    setLoading(true);
    try {
      await fetch('/api/delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ paths: [path] })
      });
      showMessage('Deleted!', 'success');
      loadFiles();
    } catch (error) {
      showMessage('Delete failed', 'error');
      setLoading(false);
    }
  };

  const handleCopy = (item) => {
    setClipboard({ items: [item], operation: 'copy' });
    showMessage('Copied to clipboard', 'info');
  };

  const handleCut = (item) => {
    setClipboard({ items: [item], operation: 'cut' });
    showMessage('Cut to clipboard', 'info');
  };

  const handlePaste = async () => {
    if (!clipboard) return;
    
    setLoading(true);
    const endpoint = clipboard.operation === 'copy' ? '/api/copy' : '/api/move';
    
    try {
      await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          items: clipboard.items,
          destDir: currentDir
        })
      });
      showMessage(`${clipboard.operation === 'copy' ? 'Copied' : 'Moved'} successfully!`, 'success');
      if (clipboard.operation === 'cut') setClipboard(null);
      loadFiles();
    } catch (error) {
      showMessage('Paste failed', 'error');
      setLoading(false);
    }
  };

  const createFolder = async (e) => {
    e.preventDefault();
    const name = e.target.querySelector('input').value.trim();
    if (!name) return;

    setLoading(true);
    try {
      await fetch('/api/upload', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ folderName: name, dir: currentDir })
      });
      showMessage('Folder created!', 'success');
      e.target.reset();
      loadFiles();
    } catch (error) {
      showMessage('Failed to create folder', 'error');
      setLoading(false);
    }
  };

  const breadcrumbs = currentDir ? currentDir.split('/') : [];

  return (
    <div style={{ fontFamily: 'system-ui', maxWidth: '1200px', margin: '0 auto', padding: '20px' }}>
      <div style={{ background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', color: 'white', padding: '30px', borderRadius: '10px', marginBottom: '20px' }}>
        <h1 style={{ margin: 0, fontSize: '28px' }}>📁 File Manager</h1>
        <div style={{ marginTop: '15px', display: 'flex', gap: '8px', flexWrap: 'wrap' }}>
          <button onClick={() => setCurrentDir('')} style={{ background: 'rgba(255,255,255,0.2)', border: 'none', color: 'white', padding: '5px 10px', borderRadius: '5px', cursor: 'pointer' }}>
            🏠 Home
          </button>
          {breadcrumbs.map((part, i) => (
            <span key={i}>
              <span style={{ opacity: 0.6 }}>/</span>
              <button onClick={() => setCurrentDir(breadcrumbs.slice(0, i + 1).join('/'))} style={{ background: 'rgba(255,255,255,0.2)', border: 'none', color: 'white', padding: '5px 10px', borderRadius: '5px', cursor: 'pointer', marginLeft: '8px' }}>
                {part}
              </button>
            </span>
          ))}
        </div>
      </div>

      {message.text && (
        <div style={{ padding: '12px 20px', borderRadius: '5px', marginBottom: '20px', background: message.type === 'error' ? '#fed7d7' : message.type === 'success' ? '#c6f6d5' : '#bee3f8', color: message.type === 'error' ? '#742a2a' : message.type === 'success' ? '#22543d' : '#2c5282' }}>
          {message.text}
        </div>
      )}

      <div style={{ display: 'flex', gap: '15px', marginBottom: '25px', flexWrap: 'wrap' }}>
        <form onSubmit={handleUpload} style={{ display: 'flex', gap: '10px', flex: 1 }}>
          <input type="file" multiple style={{ flex: 1, padding: '10px', border: '1px solid #ddd', borderRadius: '5px' }} />
          <button type="submit" style={{ padding: '10px 20px', background: '#667eea', color: 'white', border: 'none', borderRadius: '5px', cursor: 'pointer', fontWeight: 500 }} disabled={loading}>
            Upload
          </button>
        </form>
        <form onSubmit={createFolder} style={{ display: 'flex', gap: '10px', flex: 1 }}>
          <input type="text" placeholder="New folder name" style={{ flex: 1, padding: '10px', border: '1px solid #ddd', borderRadius: '5px' }} />
          <button type="submit" style={{ padding: '10px 20px', background: '#48bb78', color: 'white', border: 'none', borderRadius: '5px', cursor: 'pointer', fontWeight: 500 }} disabled={loading}>
            Create Folder
          </button>
        </form>
        {clipboard && (
          <button onClick={handlePaste} style={{ padding: '10px 20px', background: '#4299e1', color: 'white', border: 'none', borderRadius: '5px', cursor: 'pointer', fontWeight: 500 }} disabled={loading}>
            Paste ({clipboard.operation})
          </button>
        )}
      </div>

      {loading && <div style={{ textAlign: 'center', padding: '40px', color: '#718096' }}>Loading...</div>}

      {!loading && files.length === 0 && (
        <div style={{ textAlign: 'center', padding: '60px', color: '#718096' }}>
          <div style={{ fontSize: '48px', marginBottom: '10px' }}>📂</div>
          <div>No files here yet</div>
        </div>
      )}

      {!loading && files.length > 0 && (
        <div style={{ background: 'white', borderRadius: '10px', overflow: 'hidden', boxShadow: '0 2px 10px rgba(0,0,0,0.1)' }}>
          {files.map((file, i) => (
            <div key={i} style={{ display: 'flex', alignItems: 'center', padding: '15px', borderBottom: i < files.length - 1 ? '1px solid #eee' : 'none' }}>
              <div style={{ fontSize: '24px', marginRight: '15px' }}>{file.isDir ? '📁' : '📄'}</div>
              <div style={{ flex: 1 }}>
                {file.isDir ? (
                  <button onClick={() => setCurrentDir(currentDir ? `${currentDir}/${file.name}` : file.name)} style={{ background: 'none', border: 'none', color: '#667eea', fontWeight: 500, fontSize: '16px', cursor: 'pointer', padding: 0, textDecoration: 'underline' }}>
                    {file.name}
                  </button>
                ) : (
                  <div style={{ fontWeight: 500 }}>{file.name}</div>
                )}
                <div style={{ fontSize: '13px', color: '#718096', marginTop: '4px' }}>{file.size}</div>
              </div>
              <div style={{ display: 'flex', gap: '8px' }}>
                {!file.isDir && file.url && (
                  <a href={file.url} download={file.name} style={{ padding: '6px 12px', background: '#4299e1', color: 'white', border: 'none', borderRadius: '5px', cursor: 'pointer', fontSize: '13px', textDecoration: 'none', display: 'inline-block' }}>
                    Download
                  </a>
                )}
                {!file.isDir && file.url && /\.(jpg|jpeg|png|gif|webp|txt|md)$/i.test(file.name) && (
                  <a href={file.url} target="_blank" rel="noopener noreferrer" style={{ padding: '6px 12px', background: '#48bb78', color: 'white', border: 'none', borderRadius: '5px', cursor: 'pointer', fontSize: '13px', textDecoration: 'none', display: 'inline-block' }}>
                    View
                  </a>
                )}
                <button onClick={() => handleCopy(file)} style={{ padding: '6px 12px', background: '#9f7aea', color: 'white', border: 'none', borderRadius: '5px', cursor: 'pointer', fontSize: '13px' }}>
                  Copy
                </button>
                <button onClick={() => handleCut(file)} style={{ padding: '6px 12px', background: '#ed8936', color: 'white', border: 'none', borderRadius: '5px', cursor: 'pointer', fontSize: '13px' }}>
                  Cut
                </button>
                <button onClick={() => handleDelete(file.path)} style={{ padding: '6px 12px', background: '#f56565', color: 'white', border: 'none', borderRadius: '5px', cursor: 'pointer', fontSize: '13px' }}>
                  Delete
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
