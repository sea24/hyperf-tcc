<?php
/**
 * Created by PhpStorm.
 * User: Sixstar-Peter
 * Date: 2019/7/27
 * Time: 22:45
 */

namespace MeiQuick\Swoft\Tcc;


use Swoft\Redis\Redis;
use Swoole\Exception;

class Tcc
{
    /**
     *
     * @param $services
     * @param $interfaceClass
     * @param $tcc_methodName
     * @param $params
     * @param $tid
     * @param $obj
     * @return array
     */
    public static function Tcc($services, $interfaceClass, $tcc_methodName, $params, $tid, $obj)
    {
        vdump($tcc_methodName);
        try {
            $flag = 0;
            if ($tcc_methodName == 'confirmMethod') {
                $flag = 1;
            }
            //活动日志：记录当前事务处于哪个阶段
            $data['tcc_method'] = $tcc_methodName;
            $data['status'] = 'normal';
            self::tccStatus($tid, 3, $tcc_methodName, $data);

            //整体的并发请求，等待一组协程的结果，发起第二阶段的请求
            $wait = new WaitGroup(count($services['slave']) + 1);

            //主服务发起第一阶段的请求
            $res = $obj->send($services['master']['services'], $interfaceClass, $services['master'][$tcc_methodName], $params);
            $res['interfaceClass'] = $interfaceClass;
            $res['method'] = $tcc_methodName;
            $wait->push($res);

            //从服务发起第一阶段的请求
            foreach ($services['slave'] as $k => $slave) {
                $slaveInterfaceClass = explode("_", $slave['services'])[0];
                $slaveRes = $obj->send($slave['services'], $slaveInterfaceClass, $slave[$tcc_methodName], $params);  //默认情况下从服务没有办法发起请求
                $slaveRes['interfaceClass'] = $slaveInterfaceClass;
                $slaveRes['method'] = $tcc_methodName;
                $wait->push($slaveRes);

            }
            //当前阶段调用结果
            $res = $wait->wait();  //阻塞
            foreach ($res as $v) {
                if (empty($v['status']) || $v['status'] == 0) {
                    throw  new \Exception("Tcc error!:" . $tcc_methodName);
                    return;
                }
            }

            //假设当前操作没有任何问题
            $data['tcc_method'] = $tcc_methodName;
            $data['status'] = 'success';
            self::tccStatus($tid, 3, $tcc_methodName, $data); //整体服务的状态
            //只有在当前的方法为try时才提交
            if ($tcc_methodName == 'tryMethod') {
                //第二阶段提交
                if ($flag == 0) {
                    return self::Tcc($services, $interfaceClass, 'confirmMethod', $params, $tid, $obj);
                }
            }
            return $res;
        } catch (\Exception $e) {
            $message = $e->getMessage();
            echo 'Tcc  message:' . $e->getMessage() . ' line:' . $e->getFile() . ' file:' . $e->getLine() . PHP_EOL;
            //无论是哪个服务抛出异常,回滚所有的服务
            if (stristr($message, "Tcc error!") || stristr($message, "Rpc CircuitBreak") || stristr($message, "Rpc-Fusion")) {
                //结果异常，跟调用异常的标准不同（也是因为swoft框架做了一次重试操作）
                //回滚时记录当前的回滚次数,下面这段仅仅只是结果出现异常的回滚
                //调用出现异常(调用超时,网络出现问题,调用失败)
                //在try阶段的回滚,直接调用cancel回滚
                //在(cancel)阶段的时候出现了异常,会重试有限次数,重复调用cancel,超过最大次数,设置fail状态,抛出异常
                //在(confirm)阶段的时候,会重试有限次数,重复调用confirm,超过最大次数,调用cancel,补偿机制跟try阶段不一样
                if ($tcc_methodName == 'tryMethod') {
                    return self::Tcc($services, $interfaceClass, 'cancelMethod', $params, $tid, $obj);

                    //回滚
                } elseif ($tcc_methodName == 'cancelMethod') {
                    if (self::tccStatus($tid, 1, $tcc_methodName)) {
                        return self::Tcc($services, $interfaceClass, 'cancelMethod', $params, $tid, $obj);
                    }
                    self::tccStatus($tid, 4, $tcc_methodName);
                    return ["status" => 0, 'tid' => $tid];


                    //二阶段提交重试
                } elseif ($tcc_methodName == 'confirmMethod') {
                    if (self::tccStatus($tid, 2, $tcc_methodName)) {
                        return self::Tcc($services, $interfaceClass, $tcc_methodName, $params, $tid, $obj);
                    }
                    //意味当前已经有方法提交了，有可能执行了业务了，我们需要额外的补偿
                    //超出最大二阶段重试，执行回滚
                    return self::Tcc($services, $interfaceClass, 'cancelMethod', $params, $tid, $obj);
                }
            }
        }

    }

    public static function tccStatus($tid, $flag = 1, $tcc_method = '', $data = [])
    {
        $redis = Redis::connection("tcc.redis.pool");
        $originalData = $redis->hget("Tcc", $tid);
        $originalData = json_decode($originalData, true);
        //(回滚处理)修改回滚次数,并且记录当前是哪个阶段出现了异常
        if ($flag == 1) {
            //判断当前事务重试的次数为几次,如果重试次数超过最大次数,则取消重试
            if ($originalData['retried_cancel_count'] >= $originalData['retried_max_count']) {
                $originalData['status'] = 'fail';
                $redis->hSet('Tcc', $tid, json_encode($originalData));
                return false;
            }
            $originalData['retried_cancel_count']++;
            $originalData['tcc_method'] = $tcc_method;
            $originalData['status'] = 'abnormal';
            $originalData['last_update_time'] = time();
            $redis->hSet('Tcc', $tid, json_encode($originalData));
            return true;
        }

        //(confirm处理)修改尝试次数,并且记录当前是哪个阶段出现了异常
        if ($flag == 2) {
            //判断当前事务重试的次数为几次,如果重试次数超过最大次数,则取消重试
            vdump($originalData['retried_confirm_count']);
            if ($originalData['retried_confirm_count'] <= 1) {
                $originalData['status'] = 'fail';
                $redis->hSet('Tcc', $tid, json_encode($originalData));
                return false;
            }
            $originalData['retried_confirm_count']--;
            $originalData['tcc_method'] = $tcc_method;
            $originalData['status'] = 'abnormal';
            $originalData['last_update_time'] = time();
            $redis->hSet('Tcc', $tid, json_encode($originalData));
            return true;
        }
        //修改当前事务的阶段
        if ($flag == 3) {
            if (!empty($data)) {
                $originalData['tcc_method'] = $data['tcc_method'];
                $originalData['status'] = $data['status'];
                $originalData['last_update_time'] = time();
                $redis->hSet('Tcc', $tid, json_encode($originalData)); //主服务状态
            }
        }

        //删除当前事务，走人工通道
        if ($flag == 4) {
            $redis->hDel('Tcc', $tid);
            $redis->hSet('Tcc_Error', $tid, json_encode($originalData));
        }
    }
}