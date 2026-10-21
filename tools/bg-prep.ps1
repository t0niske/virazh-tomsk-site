# Готовит фон блока для сайта: читает webp/png/jpg, при необходимости уменьшает по ширине и сохраняет JPEG.
#   powershell -ExecutionPolicy Bypass -File tools\bg-prep.ps1 -In design\bg\bg-night-road.webp -Out img\bg\bg-night-road.jpg [-Width 1600] [-Quality 74]
param([Parameter(Mandatory)][string]$In, [Parameter(Mandatory)][string]$Out, [int]$Width = 1600, [int]$Quality = 74)

Add-Type -AssemblyName PresentationCore, WindowsBase
$fs = [IO.File]::OpenRead((Resolve-Path $In))
$frame = [Windows.Media.Imaging.BitmapDecoder]::Create($fs, 'PreservePixelFormat', 'OnLoad').Frames[0]; $fs.Close()
$img = [Windows.Media.Imaging.BitmapSource]$frame
if ($frame.PixelWidth -gt $Width) {
  $k = $Width / $frame.PixelWidth
  $img = New-Object Windows.Media.Imaging.TransformedBitmap($frame, (New-Object Windows.Media.ScaleTransform($k, $k)))
}
$enc = New-Object Windows.Media.Imaging.JpegBitmapEncoder; $enc.QualityLevel = $Quality
$enc.Frames.Add([Windows.Media.Imaging.BitmapFrame]::Create($img))
$outPath = [IO.Path]::GetFullPath($Out); [IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($outPath)) | Out-Null
$o = [IO.File]::Create($outPath); $enc.Save($o); $o.Close()
"$Out  $($img.PixelWidth)x$($img.PixelHeight)  $([Math]::Round((Get-Item $outPath).Length/1KB)) KB"
