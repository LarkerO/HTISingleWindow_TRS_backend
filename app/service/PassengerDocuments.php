<?php
declare(strict_types=1);
namespace app\service;
use think\facade\Db;
use think\exception\HttpException;
final class PassengerDocuments
{
    private const FIELDS=['document_type','document_number','issuing_country','birth_date','surname','given_name'];
    public static function actor(array $identity): array {
        $role=$identity['role']??'';
        if(!in_array($role,['admin','agency','operator'],true))throw new HttpException(403,'Authenticated actor required');
        return ['actor_role'=>$role,'actor_code'=>$role==='admin'?'ADMIN':($identity[$role==='agency'?'agency_code':'operator_code']??'')];
    }
    private function text(array $body,string $key,int $max,bool $optional=false): ?string {
        if($optional && (!isset($body[$key])||$body[$key]===''))return null;
        if(!isset($body[$key])||!is_string($body[$key]))throw new HttpException(422,$key.' must be a string');
        $value=trim($body[$key]);
        if($value===''&&$optional)return null;
        if($value===''||mb_strlen($value)>$max||preg_match('/[\x00-\x1F\x7F]/',$value))throw new HttpException(422,'Invalid '.$key);
        return $value;
    }
    private function values(array $body): array {
        foreach(['id','created_at','updated_at'] as $field)if(array_key_exists($field,$body))throw new HttpException(422,$field.' is server-managed');
        $values=[];
        $values['document_type']=strtoupper($this->text($body,'document_type',32));
        $values['document_number']=strtoupper($this->text($body,'document_number',80));
        $values['issuing_country']=strtoupper($this->text($body,'issuing_country',3));
        if(!preg_match('/^[A-Z]{3}$/',$values['issuing_country']))throw new HttpException(422,'issuing_country must be three letters');
        $values['birth_date']=$this->text($body,'birth_date',10);
        $birth=\DateTimeImmutable::createFromFormat('!Y-m-d',$values['birth_date'],new \DateTimeZone('UTC'));
        if(!$birth||$birth->format('Y-m-d')!==$values['birth_date']||$values['birth_date']<'1000-01-01'||$values['birth_date']>gmdate('Y-m-d'))throw new HttpException(422,'Invalid birth_date');
        $values['surname']=$this->text($body,'surname',80,true);
        $values['given_name']=$this->text($body,'given_name',80);
        if(mb_strlen(self::name($values))>160)throw new HttpException(422,'Full name exceeds 160 characters');
        return $values;
    }
    public static function name(array $document): string {return trim(($document['surname']??'').' '.$document['given_name']);}
    public static function present(array $document): array {
        $document['id']=(int)$document['id'];$document['version']=(int)$document['version'];
        $document['display_name']=self::name($document);return $document;
    }
    public function get(int $id): array {
        $row=Db::table('passenger_documents')->where('id',$id)->find();
        if(!$row)throw new HttpException(404,'Passenger document not found');
        return self::present($row);
    }
    public function search(array $filters,int $page=1): array {
        $query=Db::table('passenger_documents');
        foreach(['document_type','document_number','issuing_country'] as $field) {
            $value=$filters[$field]??'';
            if(!is_string($value))throw new HttpException(422,'Invalid '.$field);
            if(trim($value)!=='')$query->where($field,strtoupper(trim($value)));
        }
        $name=$filters['name']??'';
        if(!is_string($name)||mb_strlen($name)>160)throw new HttpException(422,'Invalid name');
        if(trim($name)!=='') {
            $escaped=str_replace(['!','%','_'],['!!','!%','!_'],trim($name));
            $query->whereRaw("(given_name LIKE ? ESCAPE '!' OR surname LIKE ? ESCAPE '!')",['%'.$escaped.'%','%'.$escaped.'%']);
        }
        $total=(clone $query)->count();$page=max(1,min(100000,$page));
        $rows=$query->order('id','desc')->page($page,30)->select()->toArray();
        return ['data'=>array_map([self::class,'present'],$rows),'total'=>$total,'page'=>$page,'page_size'=>30];
    }
    public function save(array $body,array $identity,?int $id=null): array {
        if(!in_array($identity['role']??'',['admin','agency'],true))throw new HttpException(403,'Passenger document management requires agency or administrator');
        $values=$this->values($body);$actor=self::actor($identity);
        $reason=$this->text($body,'reason',500);
        if($id!==null && (!isset($body['version'])||!is_int($body['version'])||$body['version']<1))throw new HttpException(422,'Current version is required');
        try {
            return Db::transaction(function()use($values,$actor,$reason,$body,$id) {
                $before=$id===null?null:Db::table('passenger_documents')->where('id',$id)->lock(true)->find();
                if($id!==null&&!$before)throw new HttpException(404,'Passenger document not found');
                if($before && (int)$before['version']!==$body['version'])throw new HttpException(409,'Document changed; reload current version');
                if($before && !array_diff_assoc($values,array_intersect_key($before,array_flip(self::FIELDS))))return self::present($before);
                $now=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
                if($before)Db::table('passenger_documents')->where('id',$id)->update($values+['updated_at'=>$now,'version'=>(int)$before['version']+1]);
                else $id=(int)Db::table('passenger_documents')->insertGetId($values+['created_at'=>$now,'updated_at'=>$now,'version'=>1]);
                $after=$this->get($id);
                Db::table('passenger_document_events')->insert($actor+['document_id'=>$id,'action'=>$before?'UPDATE':'CREATE','reason'=>$reason,
                    'before_data'=>$before?json_encode(self::present($before),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null,
                    'after_data'=>json_encode($after,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'occurred_at'=>$now]);
                return $after;
            });
        } catch(\think\db\exception\PDOException $e) {
            if(str_contains($e->getMessage(),'Duplicate'))throw new HttpException(409,'Document type, country and number already registered');
            throw $e;
        }
    }
    public function history(int $id,int $page=1): array {
        $this->get($id);$page=max(1,min(100000,$page));$query=Db::table('passenger_document_events')->where('document_id',$id);
        $total=(clone $query)->count();$rows=$query->order('id','desc')->page($page,30)->select()->toArray();
        foreach($rows as &$row){$row['before']=json_decode($row['before_data']??'null',true);$row['after']=json_decode($row['after_data'],true);unset($row['before_data'],$row['after_data']);}
        return ['data'=>$rows,'total'=>$total,'page'=>$page,'page_size'=>30];
    }
}

