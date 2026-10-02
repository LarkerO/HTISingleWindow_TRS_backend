<?php
declare(strict_types=1);
namespace app\service;
use app\mtr\TrainNumber;
use think\exception\HttpException;
final class TripFilters
{
    public static function number(string $value): ?string {
        if(trim($value)==='')return null;
        $number=TrainNumber::normalize($value);
        if($number===null)throw new HttpException(422,'train_number must be D/G/C/S followed by digits');
        return $number;
    }
    public static function window(string $date,string $from,string $to): array {
        if($from===''&&$to==='')return [null,null];
        $day=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('UTC'));
        if(!$day||$day->format('Y-m-d')!==$date)throw new HttpException(422,'A valid date is required with time filters');
        $times=[];
        foreach([$from,$to] as $time) {
            if($time===''){$times[]=null;continue;}
            if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/',$time))throw new HttpException(422,'Time must be HH:MM or HH:MM:SS (UTC)');
            $times[]=$date.' '.$time.(strlen($time)===5?':00':'');
        }
        if($times[0]!==null&&$times[1]!==null&&$times[0]>=$times[1])throw new HttpException(422,'time_to must be later than time_from on the selected day');
        return $times;
    }
}
