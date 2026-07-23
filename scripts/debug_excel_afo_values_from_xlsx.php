<?php
$sheetPath = __DIR__ . '/../uploads/NOKy_from_xlsx/xl/worksheets/sheet1.xml';
$sharedPath = __DIR__ . '/../uploads/NOKy_from_xlsx/xl/sharedStrings.xml';
if (!is_file($sheetPath)) { echo "missing_sheet\n"; exit(1); }
$shared=[];
if (is_file($sharedPath)) {
    $sx=@simplexml_load_file($sharedPath);
    if($sx){foreach($sx->si as $si){if(isset($si->t)){$shared[]=(string)$si->t;}else{$parts=[];foreach($si->r as $r){$parts[]=(string)$r->t;}$shared[]=implode('',$parts);}}}
}
$sheet=@simplexml_load_file($sheetPath);
$ns=$sheet->getNamespaces(true);
if(isset($ns[''])){$sheet->registerXPathNamespace('x',$ns['']);$rows=$sheet->xpath('//x:sheetData/x:row');}else{$rows=$sheet->sheetData->row??[];}
$first=true;$raw=[];$norm=[];
foreach($rows as $row){$a='';foreach($row->c as $c){$ref=(string)($c['r']??'');if(str_starts_with($ref,'A')){$type=(string)($c['t']??'');$v=isset($c->v)?(string)$c->v:'';$a=$type==='s'?(string)($shared[(int)$v]??''):$v;break;}}
if($first){$first=false;continue;}
$a=trim($a);if($a==='')continue;$raw[$a]=true;if(preg_match('/\d+/',$a,$m)){$norm[(string)((int)$m[0])]=true;}}
$rawKeys=array_keys($raw);sort($rawKeys,SORT_NATURAL);$normKeys=array_keys($norm);sort($normKeys,SORT_NATURAL);
echo 'raw_count='.count($rawKeys).PHP_EOL;
echo 'norm_count='.count($normKeys).PHP_EOL;
echo 'has_raw_20='.(in_array('20',$rawKeys,true)?'yes':'no').PHP_EOL;
echo 'has_norm_20='.(in_array('20',$normKeys,true)?'yes':'no').PHP_EOL;
echo 'sample_raw='.implode(', ',array_slice($rawKeys,0,40)).PHP_EOL;
