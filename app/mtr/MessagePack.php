<?php
declare(strict_types=1);
namespace app\mtr;
final class MessagePack
{
    private int $offset = 0;
    public function __construct(private string $bytes) {}
    public function decode(bool $allowStaleTail = false): mixed {
        $v=$this->value(0);
        if (!$allowStaleTail && $this->offset!==strlen($this->bytes)) throw new \RuntimeException('Trailing MessagePack data');
        return $v;
    }
    private function take(int $n): string {
        if ($n<0 || $this->offset+$n>strlen($this->bytes)) throw new \RuntimeException('Truncated MessagePack');
        $v=substr($this->bytes,$this->offset,$n); $this->offset+=$n; return $v;
    }
    private function uint(int $n): int { return unpack([1=>'C',2=>'n',4=>'N',8=>'J'][$n],$this->take($n))[1]; }
    private function signed(int $n): int { $v=$this->uint($n); return $v>=(1<<($n*8-1))?$v-(1<<($n*8)):$v; }
    private function items(int $n,bool $map,int $depth): array {
        if ($n>1000000) throw new \RuntimeException('Oversized MessagePack');
        $out=[];
        for($i=0;$i<$n;$i++) {
            if($map) { $key=$this->value($depth+1); if(!is_string($key)) throw new \RuntimeException('Invalid map key'); $out[$key]=$this->value($depth+1); }
            else $out[]=$this->value($depth+1);
        }
        return $out;
    }
    private function value(int $depth): mixed {
        if($depth>64) throw new \RuntimeException('Excessive nesting');
        $t=$this->uint(1);
        if($t<128) return $t;
        if($t>=224) return $t-256;
        if(($t&224)===160) return $this->take($t&31);
        if(($t&240)===128) return $this->items($t&15,true,$depth);
        if(($t&240)===144) return $this->items($t&15,false,$depth);
        return match($t) {
            192=>null,194=>false,195=>true,
            196,217=>$this->take($this->uint(1)),197,218=>$this->take($this->uint(2)),198,219=>$this->take($this->uint(4)),
            202=>unpack('G',$this->take(4))[1],203=>unpack('E',$this->take(8))[1],
            204=>$this->uint(1),205=>$this->uint(2),206=>$this->uint(4),207,211=>$this->uint(8),
            208=>unpack('c',$this->take(1))[1],209=>$this->signed(2),210=>$this->signed(4),
            220=>$this->items($this->uint(2),false,$depth),221=>$this->items($this->uint(4),false,$depth),
            222=>$this->items($this->uint(2),true,$depth),223=>$this->items($this->uint(4),true,$depth),
            default=>throw new \RuntimeException(sprintf('Unsupported MessagePack tag %02x',$t)),
        };
    }
}

