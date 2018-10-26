<?php

namespace Inbenta\ChatbotConnector\ExternalDigester;

use \Exception;

/**
**	Converts messages from an external service request into a ChatbotAPI messages
**	If no channel is specified, detects which of the supported channels made the request
**/
class ExternalDigester
{

	//Supported external services
	protected $channels = array(
	);

	protected $digester;

	public function __construct($channel, $lang, $request = null)
	{
		$channel = ucfirst($channel);

		//Auto-detect channel
		if( $channel == 'Auto' )
		{
			$this->digester = $this->detectChannel($request);
		}elseif( $this->channels[$channel] )
		{
			//Instance the selected channel digester
			$digesterClass = 'Inbenta\ChatbotConnector\ExternalDigester\Channels\\' . $channel . 'Digester';
			$this->digester = new $digesterClass($lang);
		}else
		{
			throw new Exception("Unknown selected channel: $channel", -1);
		}
	}

	//Detects which of the supported channels made the request
	protected function detectChannel($request)
	{
		if( is_null($request) )
		{
			throw new Exception("Channel detection failed: null request", 1);
		}

		//Check if the request matches any of the supported channels
		foreach ($this->channels as $channel => $enabled)
		{
			$digesterClass = 'Inbenta\ChatbotConnector\ExternalDigester\Channels\\' . $channel . 'Digester';

			if( $digesterClass::checkRequest($request) )
			{
				return new $digesterClass($channel);
			}
		}
		throw new Exception("Channel detection failed: no channel match", -1);
	}

	//Translates a request from an external service into Inbenta Chatbot API request
	public function digestToApi($request)
	{
		return $this->digester->digestToApi($request);
	}

	//Translates a request from the Inbenta Chatbot API into an external service request
	public function digestFromApi($request, $lastUserQuestion='')
	{
		return $this->digester->digestFromApi($request, $lastUserQuestion);
	}

	public function buildContentRatingsMessage($ratingOptions, $rateCode, $rateComment=null){
		return $this->digester->buildContentRatingsMessage($ratingOptions, $rateCode, $rateComment=null);
	}

}