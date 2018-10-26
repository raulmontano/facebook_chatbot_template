<?php

namespace Inbenta\ChatbotConnector\Utils;

// use Pimple\Container;
use Inbenta\ChatbotConnector\Utils\DotAccessor;
use Inbenta\ChatbotConnector\Utils\EnvironmentDetector;

class ConfigurationLoader
{
    protected $conf;
    protected $confPath;

    public function __construct($appPath){
        $this->conf = new DotAccessor();
        $this->confPath = $appPath.'/conf/';

        $confFiles = require realpath($this->confPath."configuration-files.php");
        $this->loadConf($confFiles, EnvironmentDetector::detect());
    }

    public function getConf(){
        return $this->conf;
    }

    public function bindConf($namespace, $file)
    {
        $this->conf->set($namespace, require realpath($file));
    }

    /**
     * Overwrites the configuration for the specified namespace
     * @param  string $namespace the configuration namespace to be overwriten
     * @param  string $file      path for the file containing the new configuration
     */
    public function mergeConf($namespace, $file)
    {
        if (!$this->conf->has($namespace)) {
            throw new ConfigurationException("The \"".$namespace."\" configuration namespace does not exist. Please use bindConf before merge");
        }
        $newConf = require realpath($file);
        $oldConf = $this->conf->get($namespace);
        $conf = array_replace_recursive($oldConf, $newConf);
        $this->conf->set($namespace, $conf);
    }

    /**
     * loads default conf and overwrites it with the one of the specified environment if it's set
     * @param  array $confPaths   array of configuration files where the key corresponds to the configuration namespace
     * @param  string $environment environment folder where the configuration files should be looked for
     * @return self
     */
    public function loadConf($confPaths, $environment = null)
    {
        foreach ($confPaths as $namespace => $file) {
            $this->bindConf($namespace, $this->confPath."/default".$file);
            if (file_exists($this->confPath."/custom".$file)) {
                $this->mergeConf($namespace, $this->confPath."/custom".$file);
            }
        }
        if ($environment) {
            $this->loadEnvironmentConf($confPaths, $environment);
        }
        return $this->conf;
    }

    /**
     * Overwrites existing conf with the one of the specified environment
     * @param  array $confPaths   array of configuration files where the key corresponds to the configuration namespace
     * @param  string $environment environment folder where the configuration files should be looked for
     * @return self
     */
    public function loadEnvironmentConf($confPaths, $environment)
    {
        foreach ($confPaths as $namespace => $file) {
            if (file_exists($this->confPath."/$environment".$file)) {
                $this->mergeConf($namespace, $this->confPath."/$environment".$file);
            }
        }
    }
}

class ConfigurationException extends ApplicationException{}
class ApplicationException extends \Exception{}
