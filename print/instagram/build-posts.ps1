# Renders the Instagram post slides from print/instagram/posts.html with headless Edge
# into print/instagram/out/<nr>-<post>/<post>-<n>.png (1080 x 1350, stories 1080 x 1920). Opens the page straight from disk.
#   powershell -ExecutionPolicy Bypass -File print/instagram/build-posts.ps1 [-Only logo,yoga]
param([string[]]$Only = @())
$Only = @($Only | ForEach-Object { $_ -split ',' } | Where-Object { $_ })
$edge = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
$page = 'file:///' + (Join-Path $PSScriptRoot 'posts.html').Replace([char]92, [char]47)
# series order (nr = position in beitraege.md) -> number of slides; slide ids in posts.html are <post>-1 ... <post>-n
$posts = [ordered]@{
  'intro' = 3; 'lagom' = 4; 'logo' = 4; 'werte' = 4; 'massage' = 5; 'yoga' = 4; 'arbeit' = 3; 'fuerwen' = 1; 'raum' = 4
  'privatpost' = 2; 'privatstory' = 1   # for Helena's private account (English)
}
$heights = @{ 'privatstory' = 1920 }      # story format; everything else is 1350
$nr = 0
foreach ($post in $posts.Keys) {
  $nr++
  if ($Only.Count -and ($Only -notcontains $post)) { continue }
  $dir = Join-Path $PSScriptRoot ("out\{0:D2}-{1}" -f $nr, $post)
  $height = if ($heights.Contains($post)) { $heights[$post] } else { 1350 }
  New-Item -ItemType Directory -Force $dir | Out-Null
  for ($n = 1; $n -le $posts[$post]; $n++) {
    $target = Join-Path $dir "$post-$n.png"
    $prof = Join-Path $env:TEMP "lagohm-edge-$(Get-Random)"
    & $edge --headless=new --disable-gpu --hide-scrollbars --allow-file-access-from-files "--user-data-dir=$prof" `
      --force-device-scale-factor=1 "--window-size=1080,$height" --virtual-time-budget=5000 `
      "--screenshot=$target" "$page`?v=$post-$n" 2>$null | Out-Null
    Start-Sleep -Seconds 2
    Remove-Item -Recurse -Force $prof -ErrorAction SilentlyContinue
    if (Test-Path $target) { "$post-$n.png" } else { "$post-$n.png was not created" }
  }
}
