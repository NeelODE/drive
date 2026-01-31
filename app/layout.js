export const metadata = {
  title: 'File Manager',
  description: 'Vercel Blob File Manager',
}

export default function RootLayout({ children }) {
  return (
    <html lang="en">
      <body style={{ margin: 0, padding: 0, background: '#f5f5f5' }}>{children}</body>
    </html>
  )
}
