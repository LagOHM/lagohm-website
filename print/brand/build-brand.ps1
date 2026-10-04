# Renders the brand PNGs from print/brand/brand.html with headless Edge (transparent background)
# and trims them to their content. The page must be served over http (fonts), e.g. port 8765.
param([string]$BaseUrl = 'http://localhost:8765/print/brand/brand.html')
Add-Type -AssemblyName System.Drawing
$edge = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
$out = Join-Path $PSScriptRoot '..\..\images\brand' | Resolve-Path
$jobs = @(
  @('insta',    'lagohm-instagram-1080.png',  1080, 1080, 1),
  @('sig',      'lagohm-signatur-quadrat.png', 400,  400, 2),
  @('sig-text', 'lagohm-signatur.png',        1000,  400, 2)
)
foreach ($j in $jobs) {
  $tmp = Join-Path $env:TEMP "lagohm-$($j[0]).png"
  $prof = Join-Path $env:TEMP "lagohm-edge-$(Get-Random)"
  & $edge --headless=new --disable-gpu --hide-scrollbars "--user-data-dir=$prof" --default-background-color=00000000 `
    "--force-device-scale-factor=$($j[4])" "--window-size=$($j[2]),$($j[3])" --virtual-time-budget=5000 `
    "--screenshot=$tmp" "$BaseUrl`?v=$($j[0])&r=$(Get-Random)" 2>$null | Out-Null
  Start-Sleep -Seconds 2
  $bmp = New-Object System.Drawing.Bitmap $tmp
  # trim fully transparent margins
  $minX = $bmp.Width; $minY = $bmp.Height; $maxX = 0; $maxY = 0
  for ($y = 0; $y -lt $bmp.Height; $y += 1) { for ($x = 0; $x -lt $bmp.Width; $x += 1) {
    if ($bmp.GetPixel($x, $y).A -gt 0) { if ($x -lt $minX) { $minX = $x }; if ($x -gt $maxX) { $maxX = $x }; if ($y -lt $minY) { $minY = $y }; if ($y -gt $maxY) { $maxY = $y } } } }
  $rect = New-Object System.Drawing.Rectangle $minX, $minY, ($maxX - $minX + 1), ($maxY - $minY + 1)
  $crop = $bmp.Clone($rect, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
  $target = Join-Path $out $j[1]; $crop.Save($target, [System.Drawing.Imaging.ImageFormat]::Png)
  "{0}: {1}x{2}" -f $j[1], $crop.Width, $crop.Height
  $crop.Dispose(); $bmp.Dispose(); Remove-Item $tmp
}
