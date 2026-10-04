# Renders the flyer HTML to a print-ready PDF with Microsoft Edge (headless).
# The page must be served over http (fonts), e.g. the local preview server on port 8765:
#   powershell -File print/build-flyer.ps1 -Name flyer-a5-de
param([string]$Name = 'flyer-a5-de', [string]$BaseUrl = 'http://localhost:8765/print')
$edge = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
$out = Join-Path $PSScriptRoot "$Name.pdf"
& $edge --headless=new --disable-gpu --no-pdf-header-footer --run-all-compositor-stages-before-draw --virtual-time-budget=5000 "--print-to-pdf=$out" "$BaseUrl/$Name.html" | Out-Null
Start-Sleep -Seconds 2
if (Test-Path $out) { "PDF: $out ({0:N0} KB)" -f ((Get-Item $out).Length / 1KB) } else { 'PDF was not created' }
