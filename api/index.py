import os
import json
import logging
from flask import Flask, request, jsonify
from werkzeug.utils import secure_filename
from werkzeug.wrappers import Response
from io import BytesIO

# ----------------------------
# App setup
# ----------------------------
app = Flask(__name__)
app.config['MAX_CONTENT_LENGTH'] = 100 * 1024 * 1024  # 100MB

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

BLOB_READ_WRITE_TOKEN = os.environ.get("BLOB_READ_WRITE_TOKEN")

if BLOB_READ_WRITE_TOKEN:
    try:
        from vercel_blob import put, delete, list as blob_list
        BLOB_ENABLED = True
    except Exception:
        BLOB_ENABLED = False
else:
    BLOB_ENABLED = False

# ----------------------------
# Utility helpers (unchanged)
# ----------------------------
def sanitize_path(path_str):
    if not path_str:
        return ""
    if "\0" in path_str:
        return None
    path_str = path_str.strip().strip("/\\")
    if path_str == "..":
        return None
    parts = path_str.split("/")
    normalized = []
    for part in parts:
        if part in ("", "."):
            continue
        if part == "..":
            if normalized:
                normalized.pop()
            else:
                return None
        else:
            normalized.append(part)
    return "/".join(normalized)

def format_bytes(bytes_val):
    if bytes_val is None or bytes_val < 0:
        return ""
    if bytes_val == 0:
        return "0 Bytes"
    suffixes = ["Bytes", "KB", "MB", "GB", "TB"]
    i = 0
    while bytes_val >= 1024 and i < len(suffixes) - 1:
        bytes_val /= 1024.0
        i += 1
    return f"{bytes_val:.2f} {suffixes[i]}"

def parse_blob_files(blobs, current_dir=""):
    files = []
    folders = set()
    current_dir = current_dir.strip("/")

    for blob in blobs:
        pathname = blob.get("pathname", "")
        if pathname.startswith("files/"):
            pathname = pathname[6:]

        if current_dir:
            if not pathname.startswith(current_dir + "/"):
                continue
            relative = pathname[len(current_dir) + 1 :]
        else:
            relative = pathname

        parts = relative.split("/")
        if parts[-1] == ".keep":
            continue

        if len(parts) == 1:
            files.append({
                "name": parts[0],
                "path": pathname,
                "is_dir": False,
                "size": format_bytes(blob.get("size", 0)),
                "size_bytes": blob.get("size", 0),
                "modified": blob.get("uploadedAt", ""),
                "url": blob.get("url", "")
            })
        else:
            folder = parts[0]
            if folder not in folders:
                folders.add(folder)
                folder_path = f"{current_dir}/{folder}" if current_dir else folder
                files.append({
                    "name": folder,
                    "path": folder_path,
                    "is_dir": True,
                    "size": "Folder",
                    "size_bytes": 0,
                    "modified": "",
                    "url": ""
                })

    files.sort(key=lambda x: (not x["is_dir"], x["name"].lower()))
    return files

# ----------------------------
# Routes (unchanged logic)
# ----------------------------
@app.route("/")
def index():
    return "<h1>Vercel Flask File Manager OK</h1>"

@app.route("/api/list")
def list_files():
    if not BLOB_ENABLED:
        return jsonify(success=False, message="Blob not configured"), 500

    sanitized = sanitize_path(request.args.get("dir", ""))
    if sanitized is None:
        return jsonify(success=False, message="Invalid directory"), 400

    result = blob_list(prefix="files/", token=BLOB_READ_WRITE_TOKEN)
    files = parse_blob_files(result.get("blobs", []), sanitized)
    return jsonify(success=True, files=files)

# (upload / create-folder / delete routes stay exactly as you wrote them)

# ----------------------------
# Vercel entrypoint (CRITICAL)
# ----------------------------
def handler(request):
    """
    Vercel serverless adapter for Flask (WSGI).
    """
    environ = {
        "REQUEST_METHOD": request.method,
        "PATH_INFO": request.path,
        "QUERY_STRING": request.query_string.decode(),
        "SERVER_NAME": "vercel",
        "SERVER_PORT": "443",
        "wsgi.version": (1, 0),
        "wsgi.url_scheme": "https",
        "wsgi.input": BytesIO(request.body or b""),
        "wsgi.errors": BytesIO(),
        "wsgi.multithread": False,
        "wsgi.multiprocess": False,
        "wsgi.run_once": True,
    }

    for key, value in request.headers.items():
        environ[f"HTTP_{key.upper().replace('-', '_')}"] = value

    response = Response.from_app(app, environ)
    return {
        "statusCode": response.status_code,
        "headers": dict(response.headers),
        "body": response.get_data(as_text=True),
    }
