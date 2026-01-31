import { copy, put } from '@vercel/blob';
import { NextResponse } from 'next/server';

export async function POST(request) {
  try {
    const { items, destDir } = await request.json();
    
    if (!items || !items.length) {
      return NextResponse.json({ error: 'No items provided' }, { status: 400 });
    }
    
    for (const item of items) {
      if (!item.isDir && item.url) {
        // Copy file
        const response = await fetch(item.url);
        const buffer = await response.arrayBuffer();
        const destPath = destDir ? `files/${destDir}/${item.name}` : `files/${item.name}`;
        await put(destPath, Buffer.from(buffer), { access: 'public' });
      }
    }
    
    return NextResponse.json({ success: true });
  } catch (error) {
    return NextResponse.json({ error: error.message }, { status: 500 });
  }
}
