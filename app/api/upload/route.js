import { put } from '@vercel/blob';
import { NextResponse } from 'next/server';

export async function POST(request) {
  try {
    const contentType = request.headers.get('content-type');
    
    // Handle folder creation
    if (contentType?.includes('application/json')) {
      const { folderName, dir } = await request.json();
      const path = dir ? `files/${dir}/${folderName}/.keep` : `files/${folderName}/.keep`;
      await put(path, '', { access: 'public', token: 'vercel_blob_rw_fIQl5ZCVTiJidIyd_eKlTKznq7dGSklesIbP7R5WZjFllJk' });
      return NextResponse.json({ success: true });
    }
    
    // Handle file upload
    const formData = await request.formData();
    const file = formData.get('file');
    const dir = formData.get('dir') || '';
    
    if (!file) {
      return NextResponse.json({ error: 'No file uploaded' }, { status: 400 });
    }
    
    const bytes = await file.arrayBuffer();
    const buffer = Buffer.from(bytes);
    
    const path = dir ? `files/${dir}/${file.name}` : `files/${file.name}`;
    const blob = await put(path, buffer, { access: 'public', token: 'vercel_blob_rw_fIQl5ZCVTiJidIyd_eKlTKznq7dGSklesIbP7R5WZjFllJk' });
    
    return NextResponse.json({ success: true, url: blob.url });
  } catch (error) {
    return NextResponse.json({ error: error.message }, { status: 500 });
  }
}