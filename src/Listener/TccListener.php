<?php
/**
 * This file is part of Swoft.
 *
 * @link https://swoft.org
 * @document https://doc.swoft.org
 * @contact group@swoft.org
 * @license https://github.com/swoft-cloud/swoft/blob/master/LICENSE
 */

namespace MeiQuick\Swoft\Tcc\Listener;
use App\Rpc\Lib\OrderInterface;
use App\Rpc\Lib\PayAccountInterface;
use Co\Context;
use Swoft\Rpc\Client\Annotation\Mapping\Reference;
use Swoft\Rpc\Client\ServiceContext;
use Six\Tcc\Annotation\Mapping\Compensable;
use Swoft\Bean\Annotation\Mapping\Inject;
use Swoft\Event\Annotation\Mapping\Listener;
use Swoft\Event\EventHandlerInterface;
use Swoft\Redis\Pool;
use Swoft\Server\ServerEvent;
use Swoft\Server\Swoole\SwooleEvent;


/**
 * Task finish handler
 *
 * @Listener(SwooleEvent::START)
 */
class TaskFinish implements EventHandlerInterface
{
    /**
     *@Inject("redis.pool")
     *@var Pool
     */
    protected  $connection;

    /**
     * @Reference(pool="order.pool",fallback="OrderFallback",type="tcc")
     *
     * @var OrderInterface
     */
    private $orderService;

    /**
     * @Reference(pool="payAccount.pool",fallback="PayAccountFallback",type="tcc")
     *
     * @var PayAccountInterface
     */
    private $payAccountService;

    /**
     *
     * @Compensable(
     *     master={"services"=OrderInterface::class,"tryMethod"="creditOrderTcc","confirmMethod"="confirmCreditOrderTcc","cancelMethod"="cancelCreditOrderTcc"},
     *     slave ={
     *            {"services"=PayAccountInterface::class,"tryMethod"="creditAccountTcc","confirmMethod"="confirmCreditAccountTcc","cancelMethod"="cancelCreditAccountTcc"}
     *          }
     * )
     *
     * @return array
     */
    public function handle($event):void
    {
        swoole_timer_tick(2000,function (){
            //查询状态正常持久化到日志组件，状态不正常的,提交到一半就结束(1.只完成了一个阶段的 2.只完成了某个服务)
            $timeOut=5;
            sgo(function()use($timeOut){
                try{
                    //自动初始化一个Context上下文对象(协程环境下)
                    $context = ServiceContext::new();
                    \Swoft\Context\Context::set($context);
                    $data=$this->connection->hGetAll('Tcc');
                    foreach ($data as $k=>$v){
                        $v=json_decode($v,true);
                        //跳过尚未超时正在执行的任务
                        if($v['last_update_time']+$timeOut > time()){
                            continue;
                        }
                        //表示当前服务异常了
                        if($v['status']!='success' && $v['tcc_method']!='confirmMethod'){
                            //在try阶段的回滚,直接调用cancel回滚
                            //在(confirm)阶段的时候,会重试有限次数,重复调用confirm,超过最大次数,调用cancel,补偿机制跟try阶段不一样
                            //在(cancel)阶段的时候出现了异常,会重试有限次数,重复调用cancel,超过最大次数,设置fail状态,抛出异常
                            if ($v['tcc_method'] == 'tryMethod') {
                                //直接调用回滚
                                var_dump("tryMethod回滚");
                                $v['tcc_method']='cancelMethod';
                                $res=$this->orderService->cancelCreditOrderTcc($v);
                                var_dump($res);

                            } elseif ($v['tcc_method'] == 'cancelMethod') {
                                //判断在异常事务处理当中的尝试次数
                                if (self::tccStatus($v['tid'], 1, 'cancelMethod')) {
                                    $this->orderService->cancelCreditOrderTcc($v);
                                }else{
                                    //发邮件,报警
                                    var_dump("cancel异常删除");
                                    $this->connection->hDel('Tcc',$k);
                                }
                            } elseif ($v['tcc_method'] == 'confirmMethod') {
                                var_dump("confirmMethod回滚或者提交");
                                  //也要去判断下当前的尝试次数
                                if (self::tccStatus($v['tid'],2, 'cancelMethod')) {
                                    //$this->orderService->confirmCreditOrderTcc($v);
                                }
                                //意味当前已经有方法提交了，有可能执行了业务了，我们需要额外的补偿
                                $params[0]['cancel_confirm_flag']=1;
                            }
                        }elseif($v['status']=='success' && $v['tcc_method']=='cancelMethod'){
                            //redis当中删除并且持久化到日志组件当中
                            var_dump("cancel成功正常删除");
                            $this->connection->hDel('Tcc',$k);
                        }elseif ($v['status']=='success' && $v['tcc_method']=='confirmMethod'){
                            //redis当中删除并且持久化到日志组件当中
                            var_dump("confirm成功正常删除");
                            $this->connection->hDel('Tcc',$k);
                        }
                    }
                }catch (\Exception $e){
                    echo 'tick message:'.$e->getMessage().' line:'.$e->getFile().' file:'.$e->getFile().PHP_EOL;
                }
            });

        });

    }

    public static function tccStatus($tid, $flag = 1, $tcc_method = '')
    {
        $redis = new \Co\Redis();
        $redis->connect('127.0.0.1', 6379);
        $originalData = $redis->hget("Tcc", $tid);
        $originalData = json_decode($originalData, true);
        //(回滚处理)修改回滚次数,并且记录当前是哪个阶段出现了异常
        if ($flag == 1) {
            //判断当前事务重试的次数为几次,如果重试次数超过最大次数,则取消重试
            if ($originalData['retried_cancel_count'] >= $originalData['retried_max_count']+2) {
                $originalData['status'] = 'fail';
                $redis->hSet('Tcc', $tid, json_encode($originalData));
                return false;
            }
            //初始化的最大尝试次数
            if($originalData['retried_cancel_count']< $originalData['retried_max_count']){
                $originalData['retried_cancel_count']=$originalData['retried_max_count'];
            }
            $originalData['retried_cancel_count']++;
            $originalData['tcc_method'] = $tcc_method;
            $originalData['status'] = 'abnormal';
            $redis->hSet('Tcc', $tid, json_encode($originalData));
            return true;
        }

        //(confirm处理)修改尝试次数,并且记录当前是哪个阶段出现了异常
        if ($flag == 2) {
            //判断当前事务重试的次数为几次,如果重试次数超过最大次数,则取消重试
            if ($originalData['retried_confirm_count'] >= 1) {
                $originalData['status'] = 'fail';
                $redis->hSet('Tcc', $tid, json_encode($originalData));
                return false;
            }
            $originalData['retried_confirm_count']++;
            $originalData['tcc_method'] = $tcc_method;
            $originalData['status'] = 'abnormal';
            $redis->hSet('Tcc', $tid, json_encode($originalData));
            return true;
        }
    }
}