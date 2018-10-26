<?php

namespace Inbenta\ChatbotConnector\Utils;

use Inbenta\ChatbotConnector\Utils\DotAccessor;
use \Exception;
use Inbenta\ChatbotConnector\Utils\SlackLogger;

class LanguageManager
{
	protected $data;

	function __construct($language, $appPath){
        $path = $appPath . '/lang/' . $language . ".php";
        if( file_exists($path) ){
            $this->data =  new DotAccessor( require realpath($path) );
        }else{
        	throw new Exception("Language '" . $language . "' not found at path '" . $path . "'", 1);        	
        }
	}

    public function translate($key, $parameters = array())
    {
    	if( $this->data->has($key) ){
    		$text = $this->data->get($key);

    		foreach ($parameters as $param => $value) {
    			$text = str_replace( '$'.$param, $value, $text);
    		}
    		return $text;
    	}
    	throw new Exception("Language string '" . $key . "' not found", 1);
    }
}
