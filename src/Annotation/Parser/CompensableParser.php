<?php declare(strict_types=1);


namespace MeiQuick\Swoft\Tcc\Annotation\Parser;

use PhpDocReader\AnnotationException;
use ReflectionException;
use Swoft\Rpc\Client\Annotation\Mapping\Reference;
use Swoft\Rpc\Client\Route;
use MeiQuick\Rpc\Tcc\Annotation\Mapping\Compensable;
use MeiQuick\Rpc\Tcc\Route\RouteRegister;
use Swoft\Annotation\Annotation\Mapping\AnnotationParser;
use Swoft\Annotation\Annotation\Parser\Parser;
use Swoft\Bean\Annotation\Mapping\Bean;
use Swoft\Proxy\Exception\ProxyException;
use Swoft\Rpc\Client\Annotation\Mapping\Fallback;
use Swoft\Rpc\Client\Exception\RpcClientException;

/**
 * @since 2.0
 *
 * @AnnotationParser(Compensable::class)
 */
class CompensableParser extends Parser
{
    /**
     * @param int $type
     * @param Reference $annotationObject
     * @return array
     * @throws RpcClientException
     * @throws AnnotationException
     * @throws ReflectionException
     * @throws ProxyException
     */
    public function parse(int $type, $annotationObject): array
    {
        $service = $annotationObject->getTccService();
        RouteRegister::register($service);
        //返回当前类名注册到框架的bean容器当中
        return [$this->className, $this->className, Bean::SINGLETON, ''];
    }
}