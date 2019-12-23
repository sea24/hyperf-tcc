<?php declare(strict_types=1);

namespace MeiQuick\Swoft\Tcc\Annotation\Mapping;

use Doctrine\Common\Annotations\Annotation\Attribute;
use Doctrine\Common\Annotations\Annotation\Attributes;
use Doctrine\Common\Annotations\Annotation\Required;
use Doctrine\Common\Annotations\Annotation\Target;
use Swoft\Rpc\Protocol;

/**
 * Class Reference
 *
 * @since 2.0
 *
 * @Annotation
 * @Target("METHOD")
 * @Attributes({
 *     @Attribute("event", type="string"),
 * })
 */
class MeiQuickTcc
{

    protected $tccService;

    /**
     * Reference constructor.
     *
     * @param array $values
     */
    public function __construct(array $values)
    {
        //验证
        if (!empty($values)) {
            $this->tccService = $values;
        }
    }

    /**
     * @return string
     */
    public function getTccService(): array
    {
        return $this->tccService;
    }
}