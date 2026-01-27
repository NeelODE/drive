export default async function handler(req, res) {
  const targetBase = "https://gotadollar.rf.gd";

  // Build target URL
  const url = new URL(req.url, "http://localhost");
  const targetUrl = targetBase + url.pathname + url.search;

  // Clone headers (strip host)
  const headers = { ...req.headers };
  delete headers.host;

  const options = {
    method: req.method,
    headers
  };

  // Forward body if present
  if (req.method !== "GET" && req.method !== "HEAD") {
    options.body = req.body;
  }

  const response = await fetch(targetUrl, options);

  // Forward status
  res.status(response.status);

  // Forward headers (safe ones)
  response.headers.forEach((value, key) => {
    if (!["content-encoding", "transfer-encoding"].includes(key)) {
      res.setHeader(key, value);
    }
  });

  const buffer = Buffer.from(await response.arrayBuffer());
  res.send(buffer);
}
