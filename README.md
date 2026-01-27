# File Manager - Vercel Blob Edition

A Python Flask file manager with **persistent Vercel Blob storage**. Your files stay safe across deployments!

## ✨ Features

- 📁 Browse files and folders
- ⬆️ Upload files
- 📂 Create new folders
- ⬇️ Download files
- 🗑️ Delete files and folders
- 💾 **Persistent storage** with Vercel Blob
- 🎨 Modern, responsive UI

## 🔐 Setup Vercel Blob Storage

Before deploying, you need to set up Vercel Blob:

1. **Create a Vercel Blob Store**:
   - Go to your Vercel Dashboard
   - Navigate to **Storage** tab
   - Click **Create Database** → Select **Blob**
   - Give it a name (e.g., "file-manager-storage")

2. **Get Your Token**:
   - After creating, you'll see environment variables
   - Copy the `BLOB_READ_WRITE_TOKEN` value
   - It looks like: `vercel_blob_rw_XXXXXXXXXXXX`

3. **Set Environment Variable**:
   - In your Vercel project settings
   - Go to **Settings** → **Environment Variables**
   - Add: `BLOB_READ_WRITE_TOKEN` with your token value
   - Make sure it's enabled for Production, Preview, and Development

## Deployment to Vercel

### Quick Deploy (Recommended)

1. **Push to GitHub**:
   ```bash
   git add .
   git commit -m "Add file manager"
   git push origin main
   ```

2. **Deploy on Vercel**:
   - Go to https://vercel.com
   - Click "Add New" → "Project"
   - Import your GitHub repo
   - Vercel auto-detects it as Python
   - **Before clicking Deploy**, go to Environment Variables
   - Add: `BLOB_READ_WRITE_TOKEN` = (your token from above)
   - Click "Deploy"
   - Wait 1-2 minutes
   - Done! 🎉

### Using Vercel CLI

```bash
# Install Vercel CLI
npm install -g vercel

# Set environment variable
vercel env add BLOB_READ_WRITE_TOKEN

# Paste your token when prompted

# Deploy
vercel --prod
```

## Project Structure

```
.
├── api/
│   └── index.py          # Main Flask application
├── requirements.txt      # Python dependencies
├── vercel.json          # Vercel configuration
└── README.md            # This file
```

## Local Development

1. **Install dependencies**:
   ```bash
   pip install -r requirements.txt
   ```

2. **Set environment variable**:
   ```bash
   # Linux/Mac
   export BLOB_READ_WRITE_TOKEN=vercel_blob_rw_YOUR_TOKEN

   # Windows
   set BLOB_READ_WRITE_TOKEN=vercel_blob_rw_YOUR_TOKEN
   ```

3. **Run locally**:
   ```bash
   cd api
   python index.py
   ```

4. **Or use Vercel dev**:
   ```bash
   vercel dev
   ```

5. Open http://localhost:3000

## API Endpoints

- `GET /` - Main HTML interface
- `GET /api/list?dir=<path>` - List files in directory
- `POST /api/upload` - Upload file to blob storage
- `POST /api/create-folder` - Create new folder
- `POST /api/delete` - Delete files/folders from blob storage

## How It Works

- Files are stored in **Vercel Blob** (persistent cloud storage)
- Each file gets a unique URL for direct access
- Folders are represented using a `.keep` placeholder file
- All operations are serverless and scalable

## Security Features

- Path traversal prevention
- File name sanitization
- File size limits (100MB)
- Secure file operations

## Browser Support

- Modern browsers (Chrome, Firefox, Safari, Edge)
- JavaScript required
- HTML5 File API support for drag & drop

## License

Free to use and modify.