# Feed preview: the first slide of each post as the profile grid shows it (3:4 crop, newest top left = post 01).
#   powershell -ExecutionPolicy Bypass -File print/instagram/build-feed-preview.ps1
Add-Type -AssemblyName System.Drawing
$covers = Get-ChildItem (Join-Path $PSScriptRoot 'out') -Directory | Sort-Object Name | ForEach-Object { Join-Path $_.FullName ($_.Name.Substring(3) + '-1.png') }
$w = 360; $h = 480; $gap = 4
$sheet = New-Object System.Drawing.Bitmap (3 * $w + 2 * $gap), (3 * $h + 2 * $gap)
$g = [System.Drawing.Graphics]::FromImage($sheet); $g.Clear([System.Drawing.Color]::White); $g.InterpolationMode = 'HighQualityBicubic'
$i = 0
foreach ($c in $covers) {
  $img = [System.Drawing.Image]::FromFile($c)
  $src = New-Object System.Drawing.Rectangle 34, 0, 1012, 1350     # 3:4 centre crop
  $dst = New-Object System.Drawing.Rectangle (($i % 3) * ($w + $gap)), ([math]::Floor($i / 3) * ($h + $gap)), $w, $h
  $g.DrawImage($img, $dst, $src, [System.Drawing.GraphicsUnit]::Pixel); $img.Dispose(); $i++
}
$sheet.Save((Join-Path $PSScriptRoot 'out\feed-vorschau.png'), [System.Drawing.Imaging.ImageFormat]::Png)
'out\feed-vorschau.png'
