# File Manager - Python/Flask Version for Vercel

This is a Python Flask conversion of the PHP file manager, designed to run on Vercel's serverless platform.

## Features

- 📁 Browse files and folders
- ⬆️ Upload files (with drag & drop support)
- 📂 Create new folders
- 👁️ View text files and images
- ⬇️ Download files and folders (as ZIP)
- ✂️ Copy/Cut/Paste files
- 🗑️ Delete files and folders
- ✅ Batch operations (select multiple files)
- 🎨 Modern, responsive UI

## Important Notes for Vercel

⚠️ **Storage Limitation**: Vercel's serverless functions use `/tmp` directory which is:
- Temporary storage (cleared between deployments)
- Limited to 512MB
- **Not persistent** across function invocations

### For Production Use

If you need **persistent storage**, consider these alternatives:

1. **Use Vercel Blob Storage**:
   - Add `@vercel/blob` package
   - Store files in Vercel's blob storage
   - Requires modifying the code to use blob APIs

2. **Use External Storage**:
   - AWS S3
   - Cloudflare R2
   - Google Cloud Storage
   - Backblaze B2

3. **Use a Database for Metadata**:
   - Vercel Postgres
   - MongoDB Atlas
   - Supabase

## Deployment to Vercel

### Option 1: Deploy via Vercel CLI

1. Install Vercel CLI:
   ```bash
   npm install -g vercel
   ```

2. Deploy:
   ```bash
   vercel
   ```

3. Follow the prompts to link/create a project

### Option 2: Deploy via Git

1. Push your code to GitHub/GitLab/Bitbucket

2. Import the project in Vercel:
   - Go to https://vercel.com/new
   - Import your Git repository
   - Vercel will auto-detect the Python project
   - Click "Deploy"

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

1. Install dependencies:
   ```bash
   pip install -r requirements.txt
   ```

2. Run locally:
   ```bash
   python api/index.py
   ```

3. Or use Vercel dev server:
   ```bash
   vercel dev
   ```

4. Open http://localhost:3000

## Configuration

The file storage location is set in `api/index.py`:

```python
BASE_DIR = Path('/tmp/my_files')
```

For local development, you can change this to a local path:

```python
BASE_DIR = Path('./my_files')
```

## API Endpoints

- `GET /` - Main HTML interface
- `GET /api/list?dir=<path>` - List files in directory
- `POST /api/upload` - Upload file
- `POST /api/create-folder` - Create new folder
- `GET /api/view?path=<path>` - View file content
- `GET /api/download?path=<path>` - Download file/folder
- `POST /api/delete` - Delete files/folders
- `POST /api/paste` - Copy/move files

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