<?php
function safeCsvRow($stream,array $cells):void
{
 $cells=array_map(static function($value){$text=(string)$value;return preg_match('/^[\s]*[=+@\-]|^[\t\r\n]/u',$text)?"'".$text:$text;},$cells);fputcsv($stream,$cells);
}
