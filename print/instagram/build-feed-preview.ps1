# Feed preview: the first slide of each post as the profile grid shows it (3:4 crop).
# Row 1 = the three pinned posts, below = the other posts, newest top left (see beitraege.md).
#   powershell -ExecutionPolicy Bypass -File print/instagram/build-feed-preview.ps1
Add-Type -AssemblyName System.Drawing
$pinned = @('01-intro', '05-massage', '06-yoga')
$grid   = @('09-raum', '08-fuerwen', '07-arbeit', '03-logo', '04-werte', '02-lagom')   # newest first
$w = 360; $h = 480; $gap = 4; $pinGap = 24
$rows = 1 + [math]::Ceiling($grid.Count / 3)
$sheet = New-Object System.Drawing.Bitmap (3 * $w + 2 * $gap), ($rows * $h + ($rows - 2) * $gap + $pinGap)
$g = [System.Drawing.Graphics]::FromImage($sheet); $g.Clear([System.Drawing.Color]::White); $g.InterpolationMode = 'HighQualityBicubic'
$font = New-Object System.Drawing.Font 'Segoe UI', 13, ([System.Drawing.FontStyle]::Bold)
$badge = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(200, 43, 27, 22))
function Draw($dir, $x, $y, $pin) {
  $img = [System.Drawing.Image]::FromFile((Join-Path $PSScriptRoot "out\$dir\$($dir.Substring(3))-1.png"))
  $src = New-Object System.Drawing.Rectangle 34, 0, 1012, 1350     # 3:4 centre crop
  $dst = New-Object System.Drawing.Rectangle $x, $y, $w, $h
  $g.DrawImage($img, $dst, $src, [System.Drawing.GraphicsUnit]::Pixel); $img.Dispose()
  if ($pin) { $g.FillRectangle($badge, $x + $w - 92, $y + 10, 82, 30); $g.DrawString('Pin', $font, [System.Drawing.Brushes]::White, $x + $w - 78, $y + 12) }
}
for ($i = 0; $i -lt 3; $i++) { Draw $pinned[$i] ($i * ($w + $gap)) 0 $true }
for ($i = 0; $i -lt $grid.Count; $i++) {
  Draw $grid[$i] (($i % 3) * ($w + $gap)) ($h + $pinGap + [math]::Floor($i / 3) * ($h + $gap)) $false
}
$sheet.Save((Join-Path $PSScriptRoot 'out\feed-vorschau.png'), [System.Drawing.Imaging.ImageFormat]::Png)
'out\feed-vorschau.png'
