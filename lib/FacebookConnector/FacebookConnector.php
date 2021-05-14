<?php

namespace Inbenta\FacebookConnector;

use Exception;
use Inbenta\ChatbotConnector\ChatbotConnector;
use Inbenta\ChatbotConnector\Utils\SessionManager;
use Inbenta\ChatbotConnector\ChatbotAPI\ChatbotAPIClient;
use Inbenta\FacebookConnector\ExternalAPI\FacebookAPIClient;
use Inbenta\FacebookConnector\ExternalDigester\FacebookDigester;
use Inbenta\FacebookConnector\HyperChatAPI\FacebookHyperChatClient;

use Inbenta\FacebookConnector\ContinuaChatbotAPIClient;

class FacebookConnector extends ChatbotConnector
{

    public function __construct($appPath)
    {
        // Initialize and configure specific components for Facebook
        try {
            parent::__construct($appPath);
            // Initialize base components
            $request = file_get_contents('php://input');
            $conversationConf = [
                'configuration' => $this->conf->get('conversation.default'),
                'userType' => $this->conf->get('conversation.user_type'),
                'environment' => $this->environment,
                'source' => $this->conf->get('conversation.source')
            ];
            $this->session   = new SessionManager($this->getExternalIdFromRequest());
            $this->validatePreviousMessages($request);
            $this->botClient = new ContinuaChatbotAPIClient($this->conf->get('api.key'), $this->conf->get('api.secret'), $this->session, $conversationConf);

            // Retrieve FB tokens from ExtraInfo and update configuration
            $this->getTokensFromExtraInfo();

            // Handle Facebook verification challenge, if needed
            FacebookAPIClient::hookChallenge($this->conf->get('fb.verify_token'));

            // Try to get the translations from ExtraInfo and update the language manager
            $this->getTranslationsFromExtraInfo('facebook', 'translations');

            // Initialize Hyperchat events handler
            if ($this->conf->get('chat.chat.enabled')) {
                $chatEventsHandler = new FacebookHyperChatClient($this->conf->get('chat.chat'), $this->lang, $this->session, $this->conf, $this->externalClient);
                $chatEventsHandler->handleChatEvent();
            }

            // Instance application components
            $externalClient         = new FacebookAPIClient($this->conf->get('fb.page_access_token'), $request);                                                // Instance Facebook client
            $chatClient             = new FacebookHyperChatClient($this->conf->get('chat.chat'), $this->lang, $this->session, $this->conf, $externalClient);    // Instance HyperchatClient for Facebook
            $externalDigester       = new FacebookDigester($this->lang, $this->conf->get('conversation.digester'), $this->session);                             // Instance Facebook digester
            $this->initComponents($externalClient, $chatClient, $externalDigester);
        } catch (Exception $e) {
            echo json_encode(["error" => $e->getMessage()]);
            die();
        }
    }

    /**
  	 *	Handle an incoming request for the ChatBot
  	 */
  	public function handleBotActions($externalRequest)
  	{
  		$needEscalation = false;
  		$needContentRating = false;
  		foreach ($externalRequest as $message) {
  			// Check if is needed to execute any preset 'command'
  			$this->handleCommands($message);
  			// Store the last user text message to session
  			$this->saveLastTextMessage($message);
  			// Check if is needed to ask for a rating comment
  			$message = $this->checkContentRatingsComment($message);
  			// Send the messages received from the external service to the ChatbotAPI
  			$botResponse = $this->sendMessageToBot($message);
  			// Check if escalation to agent is needed
  			$needEscalation = $this->checkEscalation($botResponse) ? true : $needEscalation;
  			// Check if is needed to display content ratings
  			$hasRating = $this->checkContentRatings($botResponse);
  			$needContentRating = $hasRating ? $hasRating : $needContentRating;
  			// Send the messages received from ChatbotApi back to the external service
  			$this->sendMessagesToExternal($botResponse);
  		}

  		if($this->session->get('conversationStarted') === TRUE){
  			$this->session->set('conversationStarted', FALSE);

  			//FIXME enviar a archivo de configuracion
  			$showWelcomeMenu = 'ver menu de inicio';

  			$startMessage = [ 'message' => $showWelcomeMenu];

  			$botResponse = $this->sendMessageToBot($startMessage);

  			$this->sendMessagesToExternal($botResponse);
  		}

  		if ($needEscalation) {
  			$this->handleEscalation();
  		}
  		// Display content rating if needed and not in chat nor asking to escalate
  		if ($needContentRating && !$this->chatOnGoing() && !$this->session->get('askingForEscalation', false)) {
  			$this->displayContentRatings($needContentRating);
  		}
  	}

    /**
     *	Retrieve Facebook tokens from ExtraInfo
     */
    protected function getTokensFromExtraInfo()
    {
        $tokens = [];
        $extraInfoData = $this->botClient->getExtraInfo('facebook');
        if (isset($extraInfoData->results)) {
            foreach ($extraInfoData->results as $element) {
                $value = isset($element->value->value) ? $element->value->value : $element->value;
                $tokens[$element->name] = $value;
            }
        }
        // Store tokens in conf
        $environment = $this->environment;
        $this->conf->set('fb.verify_token', $tokens['verify_token']);
        $this->conf->set('fb.page_access_token', $tokens['page_tokens']->$environment);
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

    /**
     * Validate if the id of the recent message is not previously sent
     * this prevents double request from Facebook Messenger
     */
    private function validatePreviousMessages($_request)
    {
        $request = json_decode($_request);
        if (
            isset($request->entry) && isset($request->entry[0]) && isset($request->entry[0]->messaging)
            && isset($request->entry[0]->messaging[0]) && isset($request->entry[0]->messaging[0]->timestamp)
        ) {
            $idMessage = $request->entry[0]->messaging[0]->timestamp;
            $lastMessagesId = $this->session->get('lastMessagesId', false);
            if (!is_array($lastMessagesId)) {
                $lastMessagesId = [];
            }
            if (in_array($idMessage, $lastMessagesId)) {
                die;
            }
            $lastMessagesId[time()] = $idMessage;

            foreach ($lastMessagesId as $key => $messageSent) {
                if ((time() - 120) > $key) {
                    //Deletes the stored incomming messages with more than 120 seconds
                    unset($lastMessagesId[$key]);
                }
            }
            $this->session->set('lastMessagesId', $lastMessagesId);
        }
    }
}
