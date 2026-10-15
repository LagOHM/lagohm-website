# Copies the original photos from Fotos/ into print/instagram/img/ as upright JPGs (EXIF rotation applied),
# 1600 px on the long side, so the post HTML can use them at full Instagram resolution.
#   powershell -ExecutionPolicy Bypass -File print/instagram/prep-photos.ps1
Add-Type -AssemblyName System.Drawing
$root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$out = Join-Path $PSScriptRoot 'img'
$photos = @(
  @('Fotos\Massage\IMG_6209.JPG',             'massage-kopf.jpg'),
  @('Fotos\Massage\IMG_6211.JPG',             'massage-ruecken.jpg'),
  @('Fotos\Massage\retuschiert\IMG_6207.jpg', 'massage-knie.jpg'),
  @('Fotos\Massage\IMG_6175.JPG',             'buddha.jpg'),
  @('Fotos\Massage\IMG_6191.JPG',             'raum.jpg'),
  @('Fotos\Yoga\IMG_6271.JPG',                'yoga-sitz.jpg'),
  @('Fotos\Yoga\IMG_6231.JPG',                'yoga-baum.jpg'),
  @('Fotos\Yoga\IMG_6308.JPG',                'helena-portrait.jpg'),
  @('Fotos\Yoga\IMG_6250.JPG',                'yoga-dreieck.jpg'),
  @('Fotos\Massage\retuschiert\IMG_6208.jpg', 'massage-twist.jpg'),
  @('Fotos\Massage\IMG_6174.JPG',             'herzen.jpg'),
  @('Fotos\Massage\retuschiert\IMG_6206.jpg', 'massage-lachen.jpg'),
  @('Fotos\Yoga\IMG_6284.JPG',                'yoga-offen.jpg'),
  @('Fotos\Yoga\IMG_6254.JPG',                'yoga-ausfallschritt.jpg')
)
$codec = [System.Drawing.Imaging.ImageCodecInfo]::GetImageEncoders() | Where-Object { $_.MimeType -eq 'image/jpeg' }
$params = New-Object System.Drawing.Imaging.EncoderParameters 1
$params.Param[0] = New-Object System.Drawing.Imaging.EncoderParameter ([System.Drawing.Imaging.Encoder]::Quality), 88L
foreach ($p in $photos) {
  $img = [System.Drawing.Image]::FromFile((Join-Path $root $p[0]))
  if ($img.PropertyIdList -contains 274) {
    switch ($img.GetPropertyItem(274).Value[0]) { 3 { $img.RotateFlip('Rotate180FlipNone') } 6 { $img.RotateFlip('Rotate90FlipNone') } 8 { $img.RotateFlip('Rotate270FlipNone') } }
  }
  $s = [math]::Min(1.0, 1600.0 / [math]::Max($img.Width, $img.Height))
  $w = [int]($img.Width * $s); $h = [int]($img.Height * $s)
  $bmp = New-Object System.Drawing.Bitmap $w, $h
  $g = [System.Drawing.Graphics]::FromImage($bmp)
  $g.InterpolationMode = 'HighQualityBicubic'; $g.DrawImage($img, 0, 0, $w, $h)
  $bmp.Save((Join-Path $out $p[1]), $codec, $params)
  "{0}: {1}x{2}" -f $p[1], $w, $h
  $g.Dispose(); $bmp.Dispose(); $img.Dispose()
}
