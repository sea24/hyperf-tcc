<?php
/**
 * Created by PhpStorm.
 * User: Sixstar-Peter
 * Date: 2019/4/16
 * Time: 22:26
 */
//等待一组协程，返回最终结果
namespace MeiQuick\Swoft\Tcc;
class WaitGroup{
    private $chan;
    private  $count=0; //默认收包的数量
    /*
     * 初始化了通道
     */
    public function __construct($num=1)
    {
        $this->chan=new \chan($num);
        $this->count=$num;
    }
    /**
     * 计数器
     * 在开启一个协程的时候调用
     */
    public function add(){
        $this->count++;
    }
    /*
     * 协程处理完结果的时候调用
     */
    public  function push($data){
        $this->chan->push($data);
    }

    /**
     * 阻塞等待所有协程处理完成并返回结果
     * @return array
     */
    public  function  wait(){
        $result = [];
        for ($i = 0; $i < $this->count; $i++)
        {
            $result[]= $this->chan->pop();
        }
        return $result;
    }
}