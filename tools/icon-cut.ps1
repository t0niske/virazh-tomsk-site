# Готовит иконку для сайта: убирает белый фон (включая замкнутые «дыры», например между спицами руля),
# смягчает край без белого ореола и сохраняет PNG с прозрачностью.
#   powershell -ExecutionPolicy Bypass -File tools\icon-cut.ps1 -In design\icons\step-driving.webp -Out img\icons\step-driving.png [-Size 512]
param([Parameter(Mandatory)][string]$In, [Parameter(Mandatory)][string]$Out, [int]$Size = 512, [int]$Hard = 246, [int]$Soft = 205, [int]$MinHole = 1500)

Add-Type -AssemblyName PresentationCore, WindowsBase
Add-Type -TypeDefinition @"
using System; using System.Collections.Generic;
public static class IconCut {
  public static void Run(byte[] px, int w, int h, int hard, int soft, int minHole) {
    int n = w * h; bool[] cand = new bool[n]; bool[] bg = new bool[n]; int[] lab = new int[n];
    for (int i = 0; i < n; i++) {
      int b = px[4*i], g = px[4*i+1], r = px[4*i+2];
      int mn = Math.Min(r, Math.Min(g, b)), mx = Math.Max(r, Math.Max(g, b));
      cand[i] = px[4*i+3] < 10 || (mn >= hard && mx - mn <= 14); lab[i] = -1;
    }
    var q = new Queue<int>(); var comp = new List<int>();
    for (int s = 0; s < n; s++) {
      if (!cand[s] || lab[s] >= 0) continue;
      comp.Clear(); bool border = false; q.Enqueue(s); lab[s] = s;
      while (q.Count > 0) {
        int i = q.Dequeue(); comp.Add(i); int x = i % w, y = i / w;
        if (x == 0 || y == 0 || x == w-1 || y == h-1) border = true;
        int[] nb = { x > 0 ? i-1 : -1, x < w-1 ? i+1 : -1, y > 0 ? i-w : -1, y < h-1 ? i+w : -1 };
        foreach (int j in nb) if (j >= 0 && cand[j] && lab[j] < 0) { lab[j] = s; q.Enqueue(j); }
      }
      if (border || comp.Count >= minHole) foreach (int i in comp) bg[i] = true;
    }
    // расстояние до фона (0..3) — край смягчаем только у самого фона
    int[] dist = new int[n];
    for (int i = 0; i < n; i++) dist[i] = bg[i] ? 0 : 99;
    for (int pass = 1; pass <= 3; pass++)
      for (int i = 0; i < n; i++) {
        if (dist[i] != 99) continue; int x = i % w, y = i / w;
        if ((x > 0 && dist[i-1] == pass-1) || (x < w-1 && dist[i+1] == pass-1) || (y > 0 && dist[i-w] == pass-1) || (y < h-1 && dist[i+w] == pass-1)) dist[i] = pass;
      }
    for (int i = 0; i < n; i++) {
      if (bg[i]) { px[4*i] = px[4*i+1] = px[4*i+2] = 0; px[4*i+3] = 0; continue; }
      if (dist[i] > 3) continue;
      int b = px[4*i], g = px[4*i+1], r = px[4*i+2]; int mn = Math.Min(r, Math.Min(g, b));
      if (mn <= soft) continue;
      double a = (255.0 - mn) / (255.0 - soft); if (a < 0.02) a = 0.02;
      // убираем подмес белого фона: C = a*F + (1-a)*255
      px[4*i]   = (byte)Math.Max(0, Math.Min(255, (b - (1-a)*255) / a));
      px[4*i+1] = (byte)Math.Max(0, Math.Min(255, (g - (1-a)*255) / a));
      px[4*i+2] = (byte)Math.Max(0, Math.Min(255, (r - (1-a)*255) / a));
      px[4*i+3] = (byte)Math.Round(a * px[4*i+3]);
    }
  }
}
"@

$fs = [IO.File]::OpenRead((Resolve-Path $In))
$frame = [Windows.Media.Imaging.BitmapDecoder]::Create($fs, 'PreservePixelFormat', 'OnLoad').Frames[0]; $fs.Close()
$src = New-Object Windows.Media.Imaging.FormatConvertedBitmap($frame, [Windows.Media.PixelFormats]::Bgra32, $null, 0)
$w = $src.PixelWidth; $h = $src.PixelHeight; $stride = $w * 4
$px = New-Object byte[] ($stride * $h); $src.CopyPixels($px, $stride, 0)
[IconCut]::Run($px, $w, $h, $Hard, $Soft, $MinHole)

$cut = [Windows.Media.Imaging.BitmapSource]::Create($w, $h, 96, 96, [Windows.Media.PixelFormats]::Bgra32, $null, $px, $stride)
$k = $Size / [Math]::Max($w, $h)
$scaled = New-Object Windows.Media.Imaging.TransformedBitmap($cut, (New-Object Windows.Media.ScaleTransform($k, $k)))
$enc = New-Object Windows.Media.Imaging.PngBitmapEncoder; $enc.Frames.Add([Windows.Media.Imaging.BitmapFrame]::Create($scaled))
$outPath = [IO.Path]::GetFullPath($Out); [IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($outPath)) | Out-Null
$o = [IO.File]::Create($outPath); $enc.Save($o); $o.Close()
"$Out  $($scaled.PixelWidth)x$($scaled.PixelHeight)  $([Math]::Round((Get-Item $outPath).Length/1KB)) KB"
