import { put, del } from '@vercel/blob';
import { NextResponse } from 'next/server';

export async function POST(request) {
  try {
    const { items, destDir } = await request.json();
    
    if (!items || !items.length) {
      return NextResponse.json({ error: 'No items provided' }, { status: 400 });
    }
    
    const urlsToDelete = [];
    
    for (const item of items) {
      if (!item.isDir && item.url) {
        // Copy to new location
        const response = await fetch(item.url);
        const buffer = await response.arrayBuffer();
        const destPath = destDir ? `files/${destDir}/${item.name}` : `files/${item.name}`;
        await put(destPath, Buffer.from(buffer), { access: 'public' });
        
        // Mark for deletion
        urlsToDelete.push(item.url);
      }
    }
    
    // Delete originals
    if (urlsToDelete.length > 0) {
      await del(urlsToDelete);
    }
    
    return NextResponse.json({ success: true });
  } catch (error) {
    return NextResponse.json({ error: error.message }, { status: 500 });
  }
}
