Add-Type -AssemblyName System.Drawing
foreach ($diagramMode in @('overview','full')) {
  $diagramData = Get-Content -LiteralPath (Join-Path $PSScriptRoot "healthqueue-erd-$diagramMode.json") -Raw | ConvertFrom-Json
  $diagramBitmap = [System.Drawing.Bitmap]::new([int]$diagramData.width,[int]$diagramData.height)
  $diagramGraphics = [System.Drawing.Graphics]::FromImage($diagramBitmap)
  $diagramGraphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
  $diagramGraphics.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
  $diagramGraphics.Clear([System.Drawing.ColorTranslator]::FromHtml('#f8fafc'))
  $ink = [System.Drawing.SolidBrush]::new([System.Drawing.ColorTranslator]::FromHtml('#023047'))
  $muted = [System.Drawing.SolidBrush]::new([System.Drawing.ColorTranslator]::FromHtml('#526879'))
  $blue = [System.Drawing.SolidBrush]::new([System.Drawing.ColorTranslator]::FromHtml('#0077b3'))
  $stripe = [System.Drawing.SolidBrush]::new([System.Drawing.ColorTranslator]::FromHtml('#f1f7fb'))
  $white = [System.Drawing.Brushes]::White
  $titleFont = [System.Drawing.Font]::new('Arial',35,[System.Drawing.FontStyle]::Bold,[System.Drawing.GraphicsUnit]::Pixel)
  $subFont = [System.Drawing.Font]::new('Arial',20,[System.Drawing.FontStyle]::Regular,[System.Drawing.GraphicsUnit]::Pixel)
  $headerFont = [System.Drawing.Font]::new('Arial',22,[System.Drawing.FontStyle]::Bold,[System.Drawing.GraphicsUnit]::Pixel)
  $bodyFont = [System.Drawing.Font]::new('Arial',16,[System.Drawing.FontStyle]::Regular,[System.Drawing.GraphicsUnit]::Pixel)
  $keyFont = [System.Drawing.Font]::new('Arial',12,[System.Drawing.FontStyle]::Regular,[System.Drawing.GraphicsUnit]::Pixel)
  $diagramGraphics.DrawString('HealthQueue - Entity-Relationship Diagram',$titleFont,$ink,80,28)
  $diagramGraphics.DrawString([string]$diagramData.subtitle,$subFont,$muted,80,85)
  $diagramGraphics.DrawString('PK = primary key   |   FK = foreign key   |   Arrows: FK to referenced key   |   Roles share Users',$bodyFont,$muted,80,123)
  $edgePen = [System.Drawing.Pen]::new([System.Drawing.ColorTranslator]::FromHtml('#91a8b7'),1.7)
  $edgePen.CustomEndCap = [System.Drawing.Drawing2D.AdjustableArrowCap]::new(5,6)
  foreach ($edge in $diagramData.edges) {
    $diagramGraphics.DrawBezier($edgePen,[single]$edge.x1,[single]$edge.y1,[single]$edge.c1x,[single]$edge.y1,[single]$edge.c2x,[single]$edge.y2,[single]$edge.x2,[single]$edge.y2)
  }
  $borderPen = [System.Drawing.Pen]::new([System.Drawing.ColorTranslator]::FromHtml('#b8cbd8'),1)
  $rightFormat = [System.Drawing.StringFormat]::new()
  $rightFormat.Alignment = [System.Drawing.StringAlignment]::Far
  foreach ($node in $diagramData.nodes) {
    $x=[single]$node.x; $y=[single]$node.y; $w=[single]$node.w
    $diagramGraphics.FillRectangle($white,$x,$y,$w,[single]$node.h)
    $diagramGraphics.DrawRectangle($borderPen,$x,$y,$w,[single]$node.h)
    $diagramGraphics.FillRectangle($blue,$x,$y,$w,54)
    $diagramGraphics.DrawString([string]$node.name,$headerFont,$white,($x+16),($y+13))
    $rowIndex=0
    foreach ($row in $node.rows) {
      $rowY=$y+64+$rowIndex*26
      if ($rowIndex%2 -eq 0) { $diagramGraphics.FillRectangle($stripe,($x+1),$rowY,($w-2),26) }
      $diagramGraphics.DrawString([string]$row.key,$keyFont,$blue,($x+10),($rowY+5))
      $diagramGraphics.DrawString([string]$row.name,$bodyFont,$ink,($x+67),($rowY+2))
      $typeRect=[System.Drawing.RectangleF]::new(($x+$w-150),($rowY+5),138,22)
      $diagramGraphics.DrawString([string]$row.type,$keyFont,$muted,$typeRect,$rightFormat)
      $rowIndex++
    }
  }
  $diagramBitmap.Save((Join-Path $PSScriptRoot "healthqueue-erd-$diagramMode.png"),[System.Drawing.Imaging.ImageFormat]::Png)
  $diagramGraphics.Dispose();$diagramBitmap.Dispose()
  foreach ($resource in @($ink,$muted,$blue,$stripe,$titleFont,$subFont,$headerFont,$bodyFont,$keyFont,$edgePen,$borderPen,$rightFormat)) { $resource.Dispose() }
}
'PNG diagrams saved.'
