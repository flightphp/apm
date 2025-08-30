<?php
namespace flight\apm\presenter;

use flight\apm\presenter\PresenterAbstract;
use flight\apm\presenter\PresenterInterface;
use flight\database\PdoWrapper;

class MysqlPresenter extends PresenterAbstract implements PresenterInterface
{
    /**
     * Constructor
     *
     * @param PdoWrapper $pdoWrapper
     * @param array $config Runway Config
     */
    public function __construct(PdoWrapper $pdoWrapper, array $config)
    {
        $this->config = $config;
        $this->pdoWrapper = $pdoWrapper;
    }
}
