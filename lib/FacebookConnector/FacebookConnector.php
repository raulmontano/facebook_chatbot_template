<?php
namespace Inbenta\FacebookConnector;

use Exception;
use Inbenta\ChatbotConnector\ChatbotConnector;
use Inbenta\ChatbotConnector\Utils\SessionManager;
use Inbenta\ChatbotConnector\ChatbotAPI\ChatbotAPIClient;
use Inbenta\FacebookConnector\ExternalAPI\FacebookAPIClient;
use Inbenta\FacebookConnector\ExternalDigester\FacebookDigester;
use Inbenta\FacebookConnector\HyperChatAPI\FacebookHyperChatClient;


class FacebookConnector extends ChatbotConnector
{

	public function __construct($appPath)
	{
		// Initialize and configure specific components for Facebook
		try {
			parent::__construct($appPath);

			// Initialize base components
			$request = file_get_contents('php://input');
			$conversationConf = array('configuration' => $this->conf->get('conversation.default'), 'userType' => $this->conf->get('conversation.user_type'), 'environment' => $this->environment);
			$this->session 		= new SessionManager($this->getExternalIdFromRequest());
			$this->botClient 	= new ChatbotAPIClient($this->conf->get('api.key'), $this->conf->get('api.secret'), $this->session, $conversationConf);

			// Retrieve FB tokens from ExtraInfo and update configuration
			$this->getTokensFromExtraInfo();

			// Try to get the translations from ExtraInfo and update the language manager
			$this->getTranslationsFromExtraInfo();

			// Initialize Hyperchat events handler
			if ($this->conf->get('chat.chat.enabled')) {
				$chatEventsHandler = new FacebookHyperChatClient($this->conf->get('chat.chat'), $this->lang, $this->session, $this->conf, $this->externalClient);
				$chatEventsHandler->handleChatEvent();
			}

			// Handle Facebook verification challenge, if needed
			FacebookAPIClient::hookChallenge($this->conf->get('fb.verify_token'));

			// Instance application components
			$externalClient 		= new FacebookAPIClient($this->conf->get('fb.page_access_token'), $request);												// Instance Facebook client
			$chatClient 			= new FacebookHyperChatClient($this->conf->get('chat.chat'), $this->lang, $this->session, $this->conf, $externalClient);	// Instance HyperchatClient for Facebook
			$externalDigester 		= new FacebookDigester($this->lang, $this->conf->get('conversation.digester'));												// Instance Facebook digester
			$this->initComponents($externalClient, $chatClient, $externalDigester);
		}
		catch (Exception $e) {
			echo json_encode(["error" => $e->getMessage()]);
			die();
		}
	}

	/**
	 *	Retrieve Facebook tokens from ExtraInfo
	 */
	protected function getTokensFromExtraInfo()
	{
		$tokens = [];
		$extraInfoData = $this->botClient->getExtraInfo('facebook');
		foreach ($extraInfoData->results as $element) {
			$value = isset($element->value->value) ? $element->value->value : $element->value;
			$tokens[$element->name] = $value;
		}
		// Store tokens in conf
		$environment = $this->environment;
		$this->conf->set('fb.verify_token', $tokens['verify_token']);
		$this->conf->set('fb.page_access_token', $tokens['page_tokens']->$environment);
	}

	/**
	 *	Retrieve Language translations from ExtraInfo
	 */
	protected function getTranslationsFromExtraInfo()
	{
		$translations = [];
		$extraInfoData = $this->botClient->getExtraInfo('facebook');
		foreach ($extraInfoData->results as $element) {
			if ($element->name == 'translations') {
				$translations = json_decode(json_encode($element->value), true);
				break;
			}
		}
		$language = $this->conf->get('conversation.default.lang');
		if (isset($translations[$language]) && count($translations[$language]) && is_array($translations[$language][0])) {
			$this->lang->addTranslations($translations[$language][0]);
		}
	}

	/**
	 *	Return external id from request (Hyperchat of Facebook)
	 */
	protected function getExternalIdFromRequest()
	{
		// Try to get user_id from a Facebook message request
		$externalId = FacebookAPIClient::buildExternalIdFromRequest();
		if (is_null($externalId)) {
			// Try to get user_id from a Hyperchat event request
			$externalId = FacebookHyperChatClient::buildExternalIdFromRequest($this->conf->get('chat.chat'));
		}
		if (empty($externalId)) {
			$api_key = $this->conf->get('api.key');
			if (isset($_GET['hub_verify_token'])) {
				// Create a temporary session_id from a Facebook webhook linking request
				$externalId = "fb-challenge-" . preg_replace("/[^A-Za-z0-9 ]/", '', $api_key);
			} elseif (isset($_SERVER['HTTP_X_HOOK_SECRET'])) {
				// Create a temporary session_id from a HyperChat webhook linking request
				$externalId = "hc-challenge-" . preg_replace("/[^A-Za-z0-9 ]/", '', $api_key);
			} else {
				throw new Exception("Invalid request");
				die();
			}
		}
		return $externalId;
	}
}
