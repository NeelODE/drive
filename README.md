# 🚀 File Manager - Deployment Guide

## What You Have

A **Next.js file manager** with Vercel Blob storage that supports:
- ✅ Upload files
- ✅ Download files  
- ✅ Copy files
- ✅ Cut/Move files
- ✅ Delete files
- ✅ Create folders
- ✅ Navigate directories
- ✅ Preview images and text files

## Your Credentials

Your Vercel Blob token:
```
BLOB_READ_WRITE_TOKEN=vercel_blob_rw_fIQ15ZCVTiJidIyd_eKlTKznq7dGSk1esIbP7R5WZJFl1Jk
```

## 🎯 How to Deploy

### Step 1: Push to GitHub

```bash
cd /path/to/your/repo
git add .
git commit -m "Add Next.js file manager"
git push origin main
```

### Step 2: Deploy on Vercel

1. Go to **https://vercel.com**
2. Click **"Add New"** → **"Project"**
3. Import your GitHub repository
4. **IMPORTANT**: Add Environment Variable:
   - Click **"Environment Variables"**
   - Name: `BLOB_READ_WRITE_TOKEN`
   - Value: `vercel_blob_rw_fIQ15ZCVTiJidIyd_eKlTKznq7dGSk1esIbP7R5WZJFl1Jk`
   - Enable for: Production ✓ Preview ✓ Development ✓
5. Click **"Deploy"**
6. Wait 2-3 minutes
7. **Done!** 🎉

## 📱 How to Use

### Upload Files
- Click "Choose Files" → Select files → Click "Upload"
- Files go to current directory

### Create Folders
- Type folder name → Click "Create Folder"

### Navigate
- Click folder names to open them
- Click breadcrumb buttons to go back

### Copy Files
- Click "Copy" on any file
- Navigate to destination folder
- Click "Paste (copy)"

### Move Files
- Click "Cut" on any file
- Navigate to destination folder
- Click "Paste (cut)"
- Original file is deleted

### Delete Files
- Click "Delete" on any file/folder
- Confirm deletion

### Download Files
- Click "Download" button

### Preview Files
- Click "View" on images (.jpg, .png, etc.) or text files (.txt, .md)
- Opens in new tab

## 🏗️ Project Structure

```
.
├── app/
│   ├── api/
│   │   ├── upload/route.js    # Upload & create folders
│   │   ├── list/route.js      # List files
│   │   ├── delete/route.js    # Delete files
│   │   ├── copy/route.js      # Copy files
│   │   └── move/route.js      # Move files
│   ├── page.js                # Main UI
│   └── layout.js              # Root layout
├── package.json               # Dependencies
└── next.config.js             # Next.js config
```

## 🔧 Local Development

```bash
# Install dependencies
npm install

# Set environment variable
export BLOB_READ_WRITE_TOKEN=vercel_blob_rw_fIQ15ZCVTiJidIyd_eKlTKznq7dGSk1esIbP7R5WZJFl1Jk

# Run development server
npm run dev

# Open http://localhost:3000
```

## ⚡ Features

- **Persistent Storage**: Files stored in Vercel Blob (won't disappear)
- **Fast**: Next.js serverless functions
- **Simple UI**: No complex dependencies
- **Mobile Friendly**: Responsive design
- **Secure**: Path sanitization & access control

## 🎨 Tech Stack

- **Framework**: Next.js 14 (App Router)
- **Storage**: Vercel Blob
- **UI**: React with inline styles
- **API**: Next.js API Routes

## 📊 Storage Info

- Free tier: 5GB storage
- Unlimited files
- Fast CDN delivery
- Public access URLs

## 🐛 Troubleshooting

**Files not loading?**
- Check environment variable is set in Vercel
- Check token is correct
- Redeploy after adding env var

**Upload fails?**
- Check file size (max 4.5MB on hobby plan)
- Check internet connection

**Need help?**
- Check Vercel deployment logs
- Check browser console for errors

---

**That's it! Simple and clean. No bullshit.** 🎉
