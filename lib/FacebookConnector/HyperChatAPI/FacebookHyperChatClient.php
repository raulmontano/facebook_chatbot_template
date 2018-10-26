<?php
namespace Inbenta\FacebookConnector\HyperChatAPI;

use Inbenta\ChatbotConnector\HyperChatAPI\HyperChatClient;
use Inbenta\FacebookConnector\ExternalAPI\FacebookAPIClient;

class FacebookHyperChatClient extends HyperChatClient
{

    //Instances an external client
    protected function instanceExternalClient($externalId, $appConf)
    {
        $externalId = FacebookAPIClient::getIdFromExternalId($externalId);
        if (is_null($externalId)) {
            return null;
        }
        $externalClient = new FacebookAPIClient($appConf->get('fb.page_access_token'));
        $externalClient->setSenderFromId( $externalId );
        return $externalClient;
    }

    public function buildExternalIdFromRequest ($config)
    {
        $request = json_decode(file_get_contents('php://input'), true);

        $externalId = null;
        if (isset($request['trigger'])) {
            //Obtain user external id from the chat event
            $externalId = self::getExternalIdFromEvent($config, $request);
        }
        return $externalId;
    }
}
