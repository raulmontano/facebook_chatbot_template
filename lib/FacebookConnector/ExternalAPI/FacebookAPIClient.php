<?php
namespace Inbenta\FacebookConnector\ExternalAPI;

use Exception;
use GuzzleHttp\Client as Guzzle;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Request;

class FacebookAPIClient
{

    /**
     * The graph API URL.
     *
     * @var string
     */
    protected $graph = 'https://graph.facebook.com/v3.1/';

    /**
     * The Facebook's Page Access Token.
     *
     * @var string|null
     */
    protected $pageAccessToken;

    /**
     * Facebook User who sends the message.
     *
     * @var array|null
     */
    protected $sender;

    /**
     * The Facebook page id.
     *
     * @var string|null
     */
    public $pageId;

    /**
     * Array of user's data to retrieve when identifying (s)he
     *
     * @var array|null
     */
    public static $USER_INFO_FIELDS = array(
        'first_name',
        'last_name'
    );

    /**
     * Create a new instance.
     *
     * @param string|null $pageAccessToken
     * @param string|null $request
     */
    public function __construct($pageAccessToken = null, $request = null)
    {
        $this->pageAccessToken = $pageAccessToken;
        $this->setSenderFromRequest($request);

        //Save Facebook page id
        $request = json_decode($request);
        if (isset($request->entry) && isset($request->entry[0]) && isset($request->entry[0]->id)) {
            $this->pageId = $request->entry[0]->id;
        }
    }

    /**
     * Get the incoming messages.
     *
     * @return array
     */
    public function messages(Request $request = null)
    {
        $request = $request ?: Request::createFromGlobals();
        $request = json_decode($request->getContent(), true);

        if ($request && $request['object'] === 'page') {
            return array_reduce($request['entry'], function (array $messages, array $entry) {
                return array_merge($messages, $entry['messaging']);
            }, []);
        }
        return [];
    }

    /**
     * Send an outgoing message.
     *
     * @param array $message
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function send(array $message)
    {
        return $this->graph('POST', 'me/messages', [
            'json' => $message,
        ]);
    }

    /**
     * Get a Facebook user by ID.
     *
     * @param string $id
     * @param string $fields
     * @return array
     */
    public function user($id, $fields = 'first_name')
    {
        $response = $this->graph('GET', $id, [
            'query' => [
                'fields' => $fields,
            ],
        ]);
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Send a request to the Facebook Graph API.
     *
     * @param string $method
     * @param string $uri
     * @param array $options
     * @return ResponseInterface
     */
    protected function graph($method, $uri, array $options = [])
    {
        if (is_null($this->pageAccessToken)) {
            throw new Exception('Page Access Token is not defined');
        }

        $guzzle = new Guzzle([
            'base_uri' => $this->graph,
        ]);

        return $guzzle->request($method, $uri, array_merge_recursive($options, [
            'verify' => false,
            'query' => [
                'access_token' => $this->pageAccessToken,
            ],
        ]));
    }

    /**
     *  Establishes the Facebook sender (user) from an incoming Facebook request
     */
    protected function setSenderFromRequest($request)
    {
        $message = $this->getFirstMessageFromRequest($request);

        if (empty($message)) {
            return;
        }

        $senderId = isset($message->sender) && isset($message->sender->id) ? $message->sender->id : ''; 
        $userFields = implode(',', self::$USER_INFO_FIELDS);

        $this->sender = $this->user($senderId, $userFields);
        $this->sender['id'] = $senderId;
    }

    /**
    *   Establishes the Facebook sender (user) directly with the provided ID
    */
    public function setSenderFromId($senderID)
    {
        $userFields = implode(',', self::$USER_INFO_FIELDS);
        $this->sender = $this->user($senderID, $userFields);
        $this->sender['id'] = $senderID;
    }

    /**
    *   Returns properties of the sender object when the $key parameter is provided (and exists).
    *   If no key is provided will return the whole object
    */
    public function getSender($key = null)
    {
        $sender =  $this->sender;

        if ($key) {
            if (isset($sender[$key])) {
                return $sender[$key];
            }
            return null;
        } else {
            return $sender;
        }
    }

    public function getUserId()
    {
        $id = $this->getSender('id');
        return !is_null($id) ? $id : time();
    }

    /**
    *   Returns the full name of the user (first + last name)
    */
    public function getFullName()
    {
        return $this->getSender('first_name') . " " . $this->getSender('last_name');
    }

    /**
    *   Generates the external id used by HyperChat to identify one user as external.
    *   This external id will be used by HyperChat adapter to instance this client class from the external id
    */
    public function getExternalId()
    {
        return 'fb-' . $this->pageId . '-' . $this->getSender('id');
    }

    /**
    *   Retrieves the user id from the external ID generated by the getExternalId method    
    */
    public static function getIdFromExternalId($externalId)
    {
        $facebookInfo = explode('-', $externalId);
        if (array_shift($facebookInfo) == 'fb') {
            return end($facebookInfo);
        }
        return null;
    }

    public static function buildExternalIdFromRequest()
    {
        $request = json_decode(file_get_contents('php://input'), true);
        if (isset($request['entry']) && count($request['entry']) && isset($request['entry'][0]['messaging'])) {
            $event = $request['entry'][0];
            return "fb-" . $event['id'] . "-" . $event['messaging'][0]['sender']['id'];
        }
        return null;
    }


    public function getEmail()
    {
        return $this->getExternalId()."@facebook.com";
    }

    /**
    *   Handles the hook challenge sent by Facebook to ensure that we're the owners of the Facebook page.
    *   Requires the verify_token associated to the Facebook page 
    */
    public static function hookChallenge($verifyToken)
    {
        $request  = Request::createFromGlobals();
        if ($request->get('hub_mode') === 'subscribe' && $request->get('hub_verify_token') === $verifyToken) {
            echo $request->get('hub_challenge');
            die();
        }
    }

    /**
    *   Returns the first message in a incoming Facebok request
    */
    protected function getFirstMessageFromRequest($request)
    {
        $request = json_decode($request);
        return isset($request->entry) && isset($request->entry[0]) &&isset($request->entry[0]->messaging) && count($request->entry[0]->messaging) ? $request->entry[0]->messaging[0] : [];
    }


    /**
    *   Sends a flag to Facebook to display a notification alert as the bot is 'writing'
    *   This method can be used to disable the notification if a 'false' parameter is received
    */
    public function showBotTyping($show = true)
    {
        $action = $show ? 'typing_on' : 'typing_off';
        return $this->send([
            'recipient' => [
                'id' => $this->sender['id']
            ],
            'sender_action' => $action
        ]);
    }

    /**
    *   Sends a message to Facebook. Needs a message formatted with the Facebook notation
    */
    public function sendMessage($message)
    {
        $this->showBotTyping(true);
        return $this->send([
            'messaging_type' => 'RESPONSE',
            'recipient' => [
                'id' => $this->sender['id']
            ],
            'message' => $message
        ]);
    }

    /**
    *   Generates a text message from a string and sends it to Facebook
    */  
    public function sendTextMessage($text)
    {
        $this->sendMessage(array(
            'text' => $text
        ));
    }

    /**
     *   Generates a Facebook attachment message from HyperChat message
     */
    public function sendAttachmentMessageFromHyperChat($message)
    {
        $this->sendMessage([
            'attachment' => [
                'type' => strpos($message['type'], 'image') !== false ? 'image' : 'file',
                'payload' => [
                    'is_reusable' => false,
                    'url' => $message['fullUrl']
                ]
            ]
        ]);
    }
}
