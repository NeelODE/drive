# Quick Setup Guide

## Step 1: You Already Have Your Token! ✅

From your screenshot:
```
BLOB_READ_WRITE_TOKEN=vercel_blob_rw_fIQ15ZCVTiJidIyd_eKlTKznq7dGSk1esIbP7R5WZJFl1Jk
```

## Step 2: Push to GitHub

```bash
# In your repo directory
git add .
git commit -m "Add Vercel Blob file manager"
git push origin main
```

## Step 3: Deploy on Vercel

### Via Dashboard (Easiest):

1. Go to **https://vercel.com**
2. Click **"Add New"** → **"Project"**
3. Import your GitHub repository
4. **IMPORTANT** - Before clicking "Deploy":
   - Scroll down to **"Environment Variables"**
   - Click **"Add"**
   - Name: `BLOB_READ_WRITE_TOKEN`
   - Value: `vercel_blob_rw_fIQ15ZCVTiJidIyd_eKlTKznq7dGSk1esIbP7R5WZJFl1Jk`
   - Check: Production ✓ Preview ✓ Development ✓
5. Click **"Deploy"**
6. Wait ~1-2 minutes ⏱️
7. **Done!** 🎉

Your file manager will be live at: `your-app-name.vercel.app`

### Via CLI:

```bash
npm install -g vercel
vercel login
vercel env add BLOB_READ_WRITE_TOKEN
# Paste the token when prompted
vercel --prod
```

## Step 4: Test It!

Visit your URL and try:
- ⬆️ Upload a file
- 📁 Create a folder  
- ⬇️ Download a file
- 🔄 Refresh - files stay there!

## Why This is Better

✅ **Persistent Storage** - Files don't disappear
✅ **Cloud Storage** - Powered by Vercel Blob
✅ **5GB Free** - Hobby plan includes storage
✅ **Direct URLs** - Each file gets a download link
✅ **No Database** - Simple blob storage

## Troubleshooting

**"Blob storage not configured" error?**
- Go to Vercel Dashboard → Your Project → Settings → Environment Variables
- Make sure `BLOB_READ_WRITE_TOKEN` is there
- Redeploy: Deployments tab → Click "..." on latest → Redeploy

**Upload fails?**
- Check file size (max 100MB)
- Check Vercel logs: Dashboard → Deployments → Function Logs
- Verify your blob store is active in Vercel Storage tab

## Storage Limits

Free Tier (Hobby):
- 5 GB total storage
- Unlimited files
- 500,000 reads/month
- 100,000 writes/month

Need more? Upgrade to Pro plan.