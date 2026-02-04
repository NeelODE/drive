import { del, list } from '@vercel/blob';
import { NextResponse } from 'next/server';

export async function POST(request) {
  try {
    const { paths } = await request.json();
    
    if (!paths || !paths.length) {
      return NextResponse.json({ error: 'No paths provided' }, { status: 400 });
    }
    
    const { blobs } = await list({ prefix: 'files/', token: 'vercel_blob_rw_fIQl5ZCVTiJidIyd_eKlTKznq7dGSklesIbP7R5WZjFllJk' });
    const urlsToDelete = [];
    
    for (const path of paths) {
      const prefix = `files/${path}`;
      
      blobs.forEach(blob => {
        if (blob.pathname === prefix || blob.pathname.startsWith(prefix + '/')) {
          urlsToDelete.push(blob.url);
        }
      });
    }
    
    if (urlsToDelete.length > 0) {
      await del(urlsToDelete, { token: 'vercel_blob_rw_fIQl5ZCVTiJidIyd_eKlTKznq7dGSklesIbP7R5WZjFllJk' });
    }
    
    return NextResponse.json({ success: true, deleted: urlsToDelete.length });
  } catch (error) {
    return NextResponse.json({ error: error.message }, { status: 500 });
  }
}