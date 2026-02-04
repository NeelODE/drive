import { list } from '@vercel/blob';
import { NextResponse } from 'next/server';

export async function GET(request) {
  try {
    const { searchParams } = new URL(request.url);
    const dir = searchParams.get('dir') || '';
    
    const { blobs } = await list({ 
      prefix: `files/${dir}`,
      token: 'vercel_blob_rw_fIQl5ZCVTiJidIyd_eKlTKznq7dGSklesIbP7R5WZjFllJk'
    });
    
    const filesMap = new Map();
    const folders = new Set();
    
    blobs.forEach(blob => {
      let path = blob.pathname.replace('files/', '');
      
      if (dir) {
        if (!path.startsWith(dir + '/')) return;
        path = path.substring(dir.length + 1);
      }
      
      const parts = path.split('/');
      
      if (parts.length === 1 && parts[0]) {
        filesMap.set(path, {
          name: parts[0],
          path: dir ? `${dir}/${parts[0]}` : parts[0],
          size: formatBytes(blob.size),
          url: blob.url,
          isDir: false
        });
      } else if (parts[0]) {
        folders.add(parts[0]);
      }
    });
    
    folders.forEach(folder => {
      if (!filesMap.has(folder)) {
        filesMap.set(folder, {
          name: folder,
          path: dir ? `${dir}/${folder}` : folder,
          size: 'Folder',
          url: null,
          isDir: true
        });
      }
    });
    
    const files = Array.from(filesMap.values()).sort((a, b) => {
      if (a.isDir && !b.isDir) return -1;
      if (!a.isDir && b.isDir) return 1;
      return a.name.localeCompare(b.name);
    });
    
    return NextResponse.json({ files });
  } catch (error) {
    return NextResponse.json({ error: error.message }, { status: 500 });
  }
}

function formatBytes(bytes) {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
}