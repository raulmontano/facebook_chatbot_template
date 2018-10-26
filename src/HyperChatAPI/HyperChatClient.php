<?php

namespace Inbenta\ChatbotConnector\HyperChatAPI;

use Inbenta\Hyperchat\HyperChat as HyperChat;

use Inbenta\ChatbotConnector\ExternalAPI\FacebookAPIClient;
use Inbenta\ChatbotConnector\Utils\SessionManager;
use Inbenta\ChatbotConnector\Utils\SlackLogger;
use Inbenta\Hyperchat\Client as DefaultHyperchatClient;

abstract class HyperChatClient extends HyperChat
{

	function __construct($config, $lang, $session, $appConf, $externalClient){

        //If external client hasn't been initialized, make a new instance
        if( is_null($externalClient) ){
            //Obtain user external id from the chat event
            $externalId = $this->getExternalIdFromEvent($config);
            if( is_null($externalId) ){
                return;
            }

            //Init new sessionManager
            $session = new SessionManager( $externalId );

            //Instance External Client
            $externalClient = $this->instanceExternalClient($externalId, $appConf);

        }
        $externalServiceInterface = new ChatExternalServiceImplement($externalClient, $lang, $session);
        parent::__construct($config, $externalServiceInterface);
	}

	//Instances an external client
	abstract protected function instanceExternalClient($externalId, $appConf);

    public function handleChatEvent(){
        $request = json_decode(file_get_contents('php://input'), true);
        $isEvent = !empty($request) && isset($request['trigger']) && !empty($request['data']);
        $isHookHandshake = isset($_SERVER['HTTP_X_HOOK_SECRET']);

        if( $isEvent || $isHookHandshake ){
            $this->handleEvent();
            die();
        }
    }

    public function getExternalIdFromEvent($config){
        $client = DefaultHyperchatClient::basic(array(
            'appId' => $config['appId'],
            'region' => $config['region']
        ));

        $event = json_decode(file_get_contents('php://input'), true);
        $externalId = null;
        switch ($event['trigger']) {
            case 'messages:new':
                $chat = $client->chats->findById($event['data']['message']['chat'], array('secret' => $config['secret'] ));
                $creator = $client->users->findById($chat->chat->creator, array('secret' => $config['secret'] ))->user; //userId
                $externalId = $creator->externalId;
            break;

            case 'chats:close':
                $chat = $client->chats->findById($event['data']['chatId'], array('secret' => $config['secret'] ))->chat;
                $creator = $client->users->findById($chat->creator, array('secret' => $config['secret'] ))->user;
                $externalId = $creator->externalId; //SESSION
            break;

            case 'invitations:new':
            case 'invitations:accept':
                $chat = $client->chats->findById($event['data']['chatId'], array('secret' => $config['secret'] ));
                $creator = $client->users->findById($chat->chat->creator, array('secret' => $config['secret'] ))->user;
                $externalId = $creator->externalId;
            break;

            case 'forever:alone':
                $chat = $client->chats->findById($event['data']['chatId'], array('secret' => $config['secret'] ))->chat;
                $creator = $client->users->findById($chat->creator, array('secret' => $config['secret'] ))->user;
                $externalId = $creator->externalId;
            break;
        }
        return $externalId;
    }

    public function getChatInformation($chatId){
        return !is_null($chatId) ? parent::getChatInfo($chatId) : false;
    }

}

