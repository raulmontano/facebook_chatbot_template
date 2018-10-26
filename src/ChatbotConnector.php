<?php

namespace Inbenta\ChatbotConnector;

use Inbenta\ChatbotConnector\ChatbotAPI\ChatbotAPIClient;
use Inbenta\ChatbotConnector\Utils\ConfigurationLoader;
use Inbenta\ChatbotConnector\Utils\LanguageManager;
use Inbenta\ChatbotConnector\Utils\SessionManager;
use Inbenta\ChatbotConnector\Utils\SlackLogger;
use \Exception;

class ChatbotConnector
{
	public 	  $conf;				//App Configuration
	public 	  $lang;				//Language manager
	public 	  $externalClient;		//External service client
	public 	  $session;				//Session manager
	protected $botClient;			//Chatbot Client
	protected $digester;			//External requests digester
	protected $chatClient;			//Hyperchat client

	function __construct($appPath)
	{
		$this->conf			= (new ConfigurationLoader($appPath))->getConf();										//Load configuration
		$this->lang 		= new LanguageManager( $this->conf->get('conversation.default.lang'), $appPath );		//Instance language manager
		$this->botClient 	= new ChatbotAPIClient($this->conf->get('api.key'), $this->conf->get('api.secret'));	//Init Chatbot API Client

		//Init logger
	    SlackLogger::setSlack($this->conf->get('log.url'));
	    SlackLogger::setUsername($this->conf->get('log.username'));
	    SlackLogger::setActive($this->conf->get('log.active'));
	}

	//Initialize class components specific for an external service
	public function initComponents($externalClient, $chatClient, $externalDigester)
	{
		//Load application components
		$this->externalClient 		= $externalClient;
		$this->digester 			= $externalDigester;
		$this->chatClient 			= $chatClient;
		$this->session 				= new SessionManager( $this->externalClient->getExternalId() );		//Initialize session manager with user id

		//Init a new Chatbot conversation or recover the current one
		if( $this->session->has('sessionToken') ){
			$this->botClient->setSessionToken( $this->session->get('sessionToken') );
		}else{
			$this->startBotConversation();
		}
	}

	//Handle a request (from external service or from Hyperchat)
	public function handleRequest(){
		//Store request
		$request = file_get_contents('php://input');

		//If there is a chat active, send messages to the agent
		if( $this->chatOnGoing() ){
			$this->sendMessagesToChat($request);
			return;
		}

		//Translate the request into a ChatbotAPI request
		$externalRequest = $this->digester->digestToApi($request);

		$needEscalation = false;
		$needContentRating = false;
		foreach ($externalRequest as $message) {
			//Store the last user text message to session
			$this->saveLastTextMessage($message);

			//Send the messages received from the external service to the ChatbotAPI
			$botResponse = $this->sendMessageToBot($message);

			//Check if escalation to agent is needed
			$needEscalation = $this->checkEscalation($botResponse) ? true : $needEscalation;

			//Check if is needed to display content ratings
			$hasRating = $this->checkContentRatings($botResponse);
			$needContentRating = $hasRating ? $hasRating : $needContentRating;

			//Send the messages received from ChatbotApi back to the external service
			$this->sendMessagesToExternal( $botResponse );
		}

		//Escalate to agent if needed
		if( $needEscalation ){
			$this->escalateToAgent();
			$this->session->set('noResultsCount', 0);
		}

		//Display content ratings
		if( $needContentRating ){
			$this->displayContentRatings($needContentRating);
		}
	}

	//Checks if a bot response requires escalation to chat
	protected function checkEscalation($botResponse){
		if( !$this->chatEnabled()){
			return false;
		}

		$triesBeforeEscalation = $this->conf->get('chat.triesBeforeEscalation');

		//Parse bot messages
		if( isset($botResponse->answers) && is_array($botResponse->answers) ){
			$messages = $botResponse->answers;
		}else{
			$messages = array($botResponse);
		}

		//Check if BotApi returned 'escalate' flag on message or triesBeforeEscalation has been reached
		foreach ($messages as $msg) {
			$this->updateNoResultsCount($msg);

			$apiEscalateFlag = isset($msg->flags) && in_array('escalate', array_flip($msg->flags) );
			$triesToEscalateReached = $triesBeforeEscalation && $this->session->get('noResultsCount') >= $triesBeforeEscalation;

			if( $apiEscalateFlag || $triesToEscalateReached ){
				return true;
			}
		}
		return false;
	}

	//Updates the number of consecutive no-result answers
	protected function updateNoResultsCount($message){
		$count = $this->session->get('noResultsCount');
		if( isset($message->flags) &&  in_array('no-results', $message->flags) ){
			$count++;
		}else{
			$count = 0;
		}
		$this->session->set('noResultsCount', $count);
	}

	//Tries to start a chat with an agent
	protected function escalateToAgent(){
		$agentsAvailable = $this->chatClient->checkAgentsAvailable();
		
		if( $agentsAvailable ){
			$this->sendMessagesToExternal( $this->buildTextMessage( $this->lang->translate('creating_chat') ) );
			//Build user data for HyperChat API
			$chatData = array(
				'roomId' => $this->conf->get('chat.chat.roomId'),
				'user' => array(
					'name' 			=> $this->externalClient->getFullName(),
					'email' 		=> $this->externalClient->getEmail(),
					'externalId' 	=> $this->externalClient->getExternalId(),
					'extraInfo' 	=> array(
						'extraInfoParam1' => 'test1',
						'extraInfoParam2' => 'test2'
					)
				)
			);
			$response =  $this->chatClient->openChat($chatData);
			if( !isset($response->error) && isset($response->chat) ){
				$this->session->set('chatOnGoing', $response->chat->id);
			}else{
				$this->sendMessagesToExternal( $this->buildTextMessage( $this->lang->translate('error_creating_chat') ) );
			}
		}else{
			//Send no-agents-available message
			$this->sendMessagesToExternal( $this->buildTextMessage( $this->lang->translate('no_agents') ) );
		}
	}

	//Builds a text message in ChatbotApi format, ready to be sent through method 'sendMessagesToExternal'
	protected function buildTextMessage( $text ){
		$message = array(
			'type' => 'answer',
			'message' => $text
		);
		return (object) $message;
	}

	//Send messages to Chatbot API
	protected function sendMessageToBot( $message ){
		try {
			//Send message to bot
			if( isset($message['message']) ){
				return $this->botClient->sendMessage($message);

			//Send event track to bot
			}elseif( isset($message['type']) ){
				return $this->sendEventToBot($message);
			}
			
		} catch (Exception $e)
		{
			//If session expired, start new conversation and retry
			if( $e->getCode() == 400 && $e->getMessage() == 'Session expired' ){
				$this->startBotConversation();
				return $this->sendMessageToBot( $message );
			}

			//Log error and stop script
			SlackLogger::log('Error ' . $e->getCode() .': '. $e->getMessage(), 'force');
			SlackLogger::log($e->getTraceAsString(), 'force');
			die();
		}
	}

	//Send messages to the external service. Messages should be formatted as a ChatbotAPI response
	protected function sendMessagesToExternal( $messages ){
		//Digest the bot response into the external service format
		$digestedBotResponse = $this->digester->digestFromApi($messages,  $this->session->get('lastUserQuestion'));
		foreach ($digestedBotResponse as $message) {
			$this->externalClient->sendMessage($message);
		}
	}

	//Send messages received from external service to HyperChat
	protected function sendMessagesToChat($request){
		$digestedRequest = $this->digester->digestToApi($request);

		foreach ($digestedRequest as $message) {
			$message = (object)$message;
			$data = array(
				'user' => array(
					'externalId' => $this->externalClient->getExternalId()
				),
				'message' => isset($message->message) ? $message->message : ''
			);
			$response = $this->chatClient->sendMessage($data);
		}
	}

	//Checks if there is a chat session active for the current user
	protected function chatOnGoing(){
		if( !$this->chatEnabled()){
			return false;
		}

		$chat = $this->session->get('chatOnGoing');
		$chatInfo = $this->chatClient->getChatInformation( $chat );
		if( $chat && isset($chatInfo->status)){
			if( $chatInfo->status != 'closed' ){
				return true;
			}else{
				$this->session->set('chatOnGoing', false);
			}
		}
		return false;
	}

	protected function chatEnabled(){
		return $this->conf->get('chat.chat.enabled');
	}

	//Store the last user text message to session
	protected function saveLastTextMessage($message){
		if( isset($message['message']) && is_string($message['message']) ){
			$this->session->set('lastUserQuestion', $message['message']);
		}
	}

	//Refresh bot session token
	protected function startBotConversation(){
		$sessionToken = $this->botClient->startConversation($this->conf->get('conversation.default'), $this->conf->get('conversation.user_type'));
		$this->session->set('sessionToken', $sessionToken);
	}

	protected function checkContentRatings($botResponse){
		$ratingConf = $this->conf->get('conversation.content_ratings');
		if( !$ratingConf['enabled'] ){
			return false;
		}

		//Parse bot messages
		if( isset($botResponse->answers) && is_array($botResponse->answers) ){
			$messages = $botResponse->answers;
		}else{
			$messages = array($botResponse);
		}

		//Check messages are answer and have a rate-code
		$rateCode = false;
		foreach ($messages as $msg) {
			$isAnswer = isset($msg->type) && $msg->type == 'answer';
			$hasRatingCode = isset($msg->parameters) && isset($msg->parameters->contents) && isset($msg->parameters->contents->trackingCode);

			if( $isAnswer && $hasRatingCode){
				$rateCode = $msg->parameters->contents->trackingCode->rateCode;
			}else{
				return false;
			}
		}
		return $rateCode;
	}

	//Display content rating message
	protected function displayContentRatings($rateCode){
		$ratingOptions = $this->conf->get('conversation.content_ratings.ratings');
		$ratingMessage = $this->digester->buildContentRatingsMessage($ratingOptions, $rateCode);
		$this->externalClient->sendMessage($ratingMessage);
	}

	//Send 
	protected function sendEventToBot($event){
		$botTrackingEvents = [
			'start',
			'click',
			'rate',
			'search',
			'search_rate',
			'contact_start',
			'contact_submit',
			'contact_ticket',
			'custom',
		];

		if( !in_array($event['type'], $botTrackingEvents) ){
			die();
		}

		$response = $this->botClient->trackEvent($event);
		switch ($event['type']) {
			case 'rate':
				return $this->buildTextMessage( $this->lang->translate('thanks') );
			break;
		}
	}

}