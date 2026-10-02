<?php
declare(strict_types=1);
namespace app\mtr;
final class TrainNumber
{
    public static function normalize(string $text): ?string {
        $clean=strtoupper(preg_replace('/[^A-Za-z0-9]/','',$text));
        return preg_match('/^[DGCS][0-9]{1,16}$/',$clean)?$clean:null;
    }
    public static function extract(string $name): ?string {
        foreach(explode('|',$name) as $part) {
            $number=self::normalize($part);
            if($number!==null) return $number;
        }
        return null;
    }
}
