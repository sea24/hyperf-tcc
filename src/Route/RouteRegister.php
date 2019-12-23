<?php declare(strict_types=1);


namespace MeiQuick\Swoft\Tcc\Route;

use Swoft\Rpc\Client\Annotation\Mapping\Reference;
use Swoft\Rpc\Client\ReferenceRegister;

/**
 * Class RouteRegister
 *
 * @since 2.0
 */
class RouteRegister
{
    /**
     * @var array
     *
     * array(2) {
     * ["services"]=>
     * string(26) "App\Rpc\Lib\OrderInterface"
     * ["value"]=>
     * array(2) {
     * ["confirmMethod"]=>
     * string(21) "confirmCreditOrderTcc"
     * ["cancelMethod"]=>
     * string(20) "cancelCreditOrderTcc"
     * }，
     * ["assoc"]=>[
     * ["services"]=>
     * string(31) "App\Rpc\Lib\PayAccountInterface"
     * ["assoc"]=>
     * string(26) "App\Rpc\Lib\OrderInterface"
     * ["value"]=>
     * array(2) {
     * ["confirmMethod"]=>
     * string(23) "confirmCreditAccountTcc"
     * ["cancelMethod"]=>
     * string(22) "cancelCreditAccountTcc"
     * ]
     * }
     *
     * @example
     * [
     *    'interface' => [
     *         'version' => ''
     *
     *     ]
     * ]
     */
    private static $services = [];

    /**
     * @param $service
     */
    public static function register($service): void
    {
        echo 66666;
        $interfaceInfo = ReferenceRegister::getAllTcc();

        var_dump("-----------------------------",$interfaceInfo,"----------------------------");

        //找到所有的,注册类型为TCC的接口服务,然后替换一下interface的名称,
        foreach ($interfaceInfo as $k => $v) {
            $k_prefix = explode("_", $k)[0];
            //循环Tcc路由,如果接口服务已经存在Tcc路由当中那么就跳过
            foreach (self::$services as  $tccServices) {
                    if($tccServices['master']['services']==$k){
                         continue 2;
                    }
                    //过滤处理服务,避免产生重复
                    foreach ($tccServices['slave'] as $slave_k=>$slave){
                        if($slave['services']==$k){
                           // var_dump($k);
                            continue 3;
                        }
                    }
            }
            //替换主服务
            if($k_prefix==$service['master']['services']){
                $service['master']['services']=$k;
            }
            //替换从服务
            foreach ($service['slave'] as $slave_k => $slave_service) {
                if ($k_prefix == $slave_service['services']) {
                    $service['slave'][$slave_k]['services']=$k;
                }
            }
        }
        self::$services[] = $service;
        //var_dump("-----------------------------",self::$services,"----------------------------");
    }

    public static function getRoute(string $interClass): array
    {
        if(empty(self::$services)){
            return [];
        }
        foreach (self::$services as $s) {
            if ($s['master']['services'] == $interClass) return $s;
        }
    }
}