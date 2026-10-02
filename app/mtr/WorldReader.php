<?php
declare(strict_types=1);
namespace app\mtr;
final class WorldReader
{
    public function read(string $source,bool $requireStations=true): array {
        $out=array_fill_keys(['stations','platforms','routes','depots','sidings'],[]);
        $consume=function(string $name,string $bytes) use (&$out) {
            if(!preg_match('~(?:^|/)(stations|platforms|routes|depots|sidings)/[^/]+/[^/]+$~',$name,$m)) return;
            try { $v=(new MessagePack($bytes))->decode(true); }
            catch(\Throwable $e) { throw new \RuntimeException($name.': '.$e->getMessage(),0,$e); }
            if(!is_array($v)||!isset($v['id'])) throw new \RuntimeException('Missing id: '.$name);
            $out[$m[1]][(string)$v['id']]=$v;
        };
        if(is_file($source)) {
            $zip=new \ZipArchive();
            if($zip->open($source)!==true) throw new \RuntimeException('Cannot open zip');
            try { for($i=0;$i<$zip->numFiles;$i++) { $name=$zip->getNameIndex($i); if(preg_match('~/(stations|platforms|routes|depots|sidings)/~',$name)||preg_match('~^(stations|platforms|routes|depots|sidings)/~',$name)) { $bytes=$zip->getFromIndex($i); if($bytes===false) throw new \RuntimeException('Cannot read zip entry'); $consume($name,$bytes); } } }
            finally { $zip->close(); }
        } elseif(is_dir($source)) {
            foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source,\FilesystemIterator::SKIP_DOTS)) as $file) {
                if($file->isFile()&&preg_match('~/(stations|platforms|routes|depots|sidings)/[^/]+/[^/]+$~',str_replace('\\','/',$file->getPathname()))) $consume(str_replace('\\','/',$file->getPathname()),file_get_contents($file->getPathname()));
            }
        } else throw new \InvalidArgumentException('Source must be a dimension directory or zip');
        if($requireStations&&!$out['stations']) throw new \RuntimeException('No stations found; select a single dimension');
        return $out;
    }
    public static function position(int $packed): array {
        $x=$packed>>38; $z=($packed>>12)&0x3ffffff;
        if($z>=0x2000000) $z-=0x4000000;
        return [$x,$z];
    }
    public static function area(array $rail,array $areas): ?array {
        [$x1,$z1]=self::position($rail['pos_1']); [$x2,$z2]=self::position($rail['pos_2']);
        $x=intdiv($x1+$x2,2); $z=intdiv($z1+$z2,2);
        $matches=[];
        foreach($areas as $a) if(($a['transport_mode']??'TRAIN')===($rail['transport_mode']??'TRAIN') && $x>=min($a['x_min'],$a['x_max']) && $x<=max($a['x_min'],$a['x_max']) && $z>=min($a['z_min'],$a['z_max']) && $z<=max($a['z_min'],$a['z_max'])) $matches[]=$a;
        if(count($matches)>1) throw new \RuntimeException('Ambiguous overlapping areas for '.$rail['id']);
        return $matches[0]??null;
    }
}



