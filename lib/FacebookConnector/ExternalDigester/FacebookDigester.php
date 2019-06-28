<?php
namespace Inbenta\FacebookConnector\ExternalDigester;

use \Exception;
use Inbenta\ChatbotConnector\ExternalDigester\Channels\DigesterInterface;

class FacebookDigester extends DigesterInterface
{

	protected $conf;
	protected $channel;
	protected $session;
	protected $langManager;
	protected $externalMessageTypes = array(
		'text',
		'postback',
		'quickReply',
		'attachment',
		'sticker',
	);

	public function __construct($langManager, $conf, $session)
	{	
		$this->langManager = $langManager;
		$this->channel = 'Facebook';
		$this->conf = $conf;
		$this->session = $session;
	}

	/**
	*	Returns the name of the channel
	*/
	public function getChannel()
	{
		return $this->channel;
	}
	
	/**
	**	Checks if a request belongs to the digester channel
	**/
	public static function checkRequest($request)
	{
		$request = json_decode($request);

		$isPage 	 = isset($request->object) && $request->object == "page";
		$isMessaging = isset($request->entry) && isset($request->entry[0]) && isset($request->entry[0]->messaging);
		if ($isPage && $isMessaging && count((array)$request->entry[0]->messaging)) {
			return true;
		}
		return false;
	}

	/**
	**	Formats a channel request into an Inbenta Chatbot API request
	**/
	public function digestToApi($request)
	{
		$request = json_decode($request);
		if (is_null($request) || !isset($request->entry) || !isset($request->entry[0]) || !isset($request->entry[0]->messaging)) {
			return [];
		}

		$messages = $request->entry[0]->messaging;
		$output = [];

		foreach ($messages as $msg) {
			$msgType = $this->checkExternalMessageType($msg);
			$digester = 'digestFromFacebook' . ucfirst($msgType);

			//Check if there are more than one responses from one incoming message
			$digestedMessage = $this->$digester($msg);
			if (isset($digestedMessage['multiple_output'])) {
				foreach ($digestedMessage['multiple_output'] as $message) {
					$output[] = $message;
				}
			} else {
				$output[] = $digestedMessage;
			}
		}
		return $output;
	}

	/**
	**	Formats an Inbenta Chatbot API response into a channel request
	**/
	public function digestFromApi($request, $lastUserQuestion='')
	{
		//Parse request messages
		if (isset($request->answers) && is_array($request->answers)) {
			$messages = $request->answers;
		} elseif ($this->checkApiMessageType($request) !== null) {
			$messages = array('answers' => $request);
		} else {
			throw new Exception("Unknown ChatbotAPI response: " . json_encode($request, true));
		}

		$output = [];
		foreach ($messages as $msg) {
			$msgType = $this->checkApiMessageType($msg);
			$digester = 'digestFromApi' . ucfirst($msgType);
			$digestedMessage = $this->$digester($msg, $lastUserQuestion);

			//Check if there are more than one responses from one incoming message
			if (isset($digestedMessage['multiple_output'])) {
				foreach ($digestedMessage['multiple_output'] as $message) {
					$output[] = $message;
				}
			} else {
				$output[] = $digestedMessage;
			}
		}
		return $output;
	}

	/**
	**	Classifies the external message into one of the defined $externalMessageTypes
	**/
	protected function checkExternalMessageType($message)
	{
		foreach ($this->externalMessageTypes as $type) {
			$checker = 'isFacebook' . ucfirst($type);

			if ($this->$checker($message)) {
				return $type;
			}
		}
	}

	/**
	**	Classifies the API message into one of the defined $apiMessageTypes
	**/
	protected function checkApiMessageType($message)
	{
		foreach ( $this->apiMessageTypes as $type ) {
			$checker = 'isApi' . ucfirst($type);

			if ($this->$checker($message)) {
				return $type;
			}
		}
		return null;
	}

	/********************** EXTERNAL MESSAGE TYPE CHECKERS **********************/
	
	protected function isFacebookText($message)
	{
		$isText = isset($message->message) && isset($message->message->text) && !isset($message->message->quick_reply);
		return $isText;
	}

	protected function isFacebookPostback($message)
	{
		return isset($message->postback);
	}

	protected function isFacebookQuickReply($message)
	{
		return isset($message->message) && isset($message->message->quick_reply);
	}

	protected function isFacebookSticker($message)
	{
		return isset($message->message) && isset($message->message->attachments) && isset($message->message->sticker_id);
	}

	protected function isFacebookAttachment($message)
	{
		return isset($message->message) && isset($message->message->attachments) && !isset($message->message->sticker_id);
	}

	/********************** API MESSAGE TYPE CHECKERS **********************/
	
	protected function isApiAnswer($message)
	{
		return isset($message->type) && $message->type == 'answer';
	}

	protected function isApiPolarQuestion($message)
	{
		return isset($message->type) && $message->type == "polarQuestion";
	}

	protected function isApiMultipleChoiceQuestion($message)
	{
		return isset($message->type) && $message->type == "multipleChoiceQuestion";
	}

	protected function isApiExtendedContentsAnswer($message)
	{
		return isset($message->type) && $message->type == "extendedContentsAnswer";
	}

	protected function hasTextMessage($message) {
		return isset($message->message) && is_string($message->message);
	}


	/********************** FACEBOOK MESSAGE DIGESTERS **********************/

	protected function digestFromFacebookText($message)
	{
		return array(
			'message' => $message->message->text
		);
	}

	protected function digestFromFacebookPostback($message)
	{
		return json_decode($message->postback->payload, true);
	}

	protected function digestFromFacebookQuickReply($message)
	{
		$quickReply = $message->message->quick_reply;
		return json_decode($quickReply->payload, true);
	}

	protected function digestFromFacebookAttachment($message)
	{
		$attachments = [];
		foreach ($message->message->attachments as $attachment) {
			if ($attachment->type == "location" && isset($attachment->title) && isset($attachment->url)) {
				$attachments[] = array('message' => $attachment->title .": ". $attachment->url);
			} elseif (isset($attachment->payload) && isset($attachment->payload->url)) {
				$attachments[] = array('message' => $attachment->payload->url);
			}
		}
		return ["multiple_output" => $attachments];
	}

	protected function digestFromFacebookSticker($message)
	{
		$sticker = $message->message->attachments[0];
		return array(
			'message' => $sticker->payload->url
		);
	}


	/********************** CHATBOT API MESSAGE DIGESTERS **********************/

	protected function digestFromApiAnswer($message)
	{
		$output = array();
		$urlButtonSetting = isset($this->conf['url_buttons']['attribute_name']) ? $this->conf['url_buttons']['attribute_name'] : '';

		if (strpos($message->message, '<img')) {
			// Handle a message that contains an image (<img> tag)
			$output['multiple_output'] = $this->handleMessageWithImages($message);
		} elseif (isset($message->attributes->$urlButtonSetting) && !empty($message->attributes->$urlButtonSetting)) {
			// Send a button that opens an URL
			$output = $this->buildUrlButtonMessage($message, $message->attributes->$urlButtonSetting);
		} else {
			// Add simple text-answer
			$output = ['text' => strip_tags($message->message)];
		}
		return $output;
	}

	protected function digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion)
	{
		$isMultiple = isset($message->flags) && in_array('multiple-options', $message->flags);
		$buttonTitleSetting = isset($this->conf['button_title']) ? $this->conf['button_title'] : '';

		$buttons = array();
		$message->options = array_slice($message->options, 0, 3);
		foreach ($message->options as $option) {
			$buttons []= [
                "title" => $isMultiple && isset($option->attributes->$buttonTitleSetting) ? $option->attributes->$buttonTitleSetting : $option->label,
                "type" => "postback",
                "payload" => json_encode([
					"message" => $lastUserQuestion,
					"option" => $option->value
                ])
            ];
		}
        return [
        	"attachment" => [
				"type" => "template",
				"payload" => [
					"template_type" => "button",
					"text" => strip_tags($message->message),
					"buttons" => $buttons
				]
		    ]
        ];
	}

	protected function digestFromApiPolarQuestion($message, $lastUserQuestion)
	{
		$buttons = array();
		foreach ($message->options as $option) {
			$buttons []= [
                "title" => $this->langManager->translate( $option->label ),
                "type" => "postback",
                "payload" => json_encode([
					"message" => $lastUserQuestion,
					"option" => $option->value
                ])
            ];
		}
        return [
        	"attachment" => [
				"type" => "template",
				"payload" => [
					"template_type" => "button",
					"text" => strip_tags($message->message),
					"buttons" => $buttons
				]
		    ]
        ];
	}

    protected function digestFromApiExtendedContentsAnswer($message)
    {
        $buttonTitleSetting = isset($this->conf['button_title']) ? $this->conf['button_title'] : '';
        $buttons = array();
        $message->subAnswers = array_slice($message->subAnswers, 0, 3);
        $this->session->set('federatedSubanswers', $message->subAnswers);
        foreach ($message->subAnswers as $index => $option) {
            $buttons []= [
                "title" => isset($option->attributes->$buttonTitleSetting) ? $option->attributes->$buttonTitleSetting : $option->attributes->title,
                "type" => "postback",
                "payload" => json_encode([
                    "extendedContentAnswer" => $index
                ])
            ];
        }
        return [
            "attachment" => [
                "type" => "template",
                "payload" => [
                    "template_type" => "button",
                    "text" => strip_tags($message->message),
                    "buttons" => $buttons
                ]
            ]
        ];
    }


	/********************** MISC **********************/

	public function buildContentRatingsMessage($ratingOptions, $rateCode)
	{
        $buttons = array();
        foreach ($ratingOptions as $option) {
        	$buttons[] = array(
        		'content_type' 	=> 'text',
        		'title' 		=> $this->langManager->translate( $option['label'] ),
        		'payload' 		=> json_encode([
					'askRatingComment' => isset($option['comment']) && $option['comment'],
					'isNegativeRating' => isset($option['isNegative']) && $option['isNegative'],
					'ratingData' =>	[
						'type' => 'rate',
						'data' => array(
							'code' 	  => $rateCode,
							'value'   => $option['id'],
							'comment' => null
						)
					]
				], true)
        	);
        }

        return [
            'text' => $this->langManager->translate('rate_content_intro'),
            'quick_replies' => $buttons
        ];
	}

	/**
	 *	Splits a message that contains an <img> tag into text/image/text and displays them in Facebook
	 */
	protected function handleMessageWithImages($message)
	{
		//Remove \t \n \r and HTML tags (keeping <img> tags)
		$text = str_replace(["\r\n", "\r", "\n", "\t"], '', strip_tags($message->message, "<img>"));
		//Capture all IMG tags
		preg_match_all('/<\s*img.*?src\s*=\s*"(.*?)".*?\s*\/?>/', $text, $matches, PREG_SET_ORDER, 0);

		$output = array();
		foreach ($matches as $imgData) {
			//Get the position of the img answer to split the message
			$imgPosition = strpos($text, $imgData[0]);

			//Append first text-part of the message to the answer
			$output[] = array(
				'text' => substr($text, 0, $imgPosition)
			);

			//Append the image to the answer
			$output[] = array(
				'attachment' => array(
					'type' => 'image',
					'payload' => array(
						'is_reusable' => false,
						'url' => $imgData[1]
					)
				)
			);

			//Remove the <img> part from the input string
			$position = $imgPosition+strlen($imgData[0]);
			$text = substr($text, $position);
		}

		//Check if there is missing text inside message
		if (strlen($text)) {
			$output[] = array(
				'text' => $text
			);
		}
		return $output;
	}

    /**
     *	Sends the text answer and displays an URL button
     */
    protected function buildUrlButtonMessage($message, $urlButton)
    {
        $buttonTitleProp = $this->conf['url_buttons']['button_title_var'];
        $buttonURLProp = $this->conf['url_buttons']['button_url_var'];

        if (!is_array($urlButton)) {
            $urlButton = [$urlButton];
        }

        $buttons = array();
        foreach ($urlButton as $button) {
            // If any of the urlButtons has any invalid/missing url or title, abort and send a simple text message
            if (!isset($button->$buttonURLProp) || !isset($button->$buttonTitleProp) || empty($button->$buttonURLProp) || empty($button->$buttonTitleProp)) {
                return ['text' => strip_tags($message->message)];
            }
            $buttons [] = [
                "type" => "web_url",
                "url" => $button->$buttonURLProp,
                "title" => $button->$buttonTitleProp,
                "webview_height_ratio" => "full"
            ];
        }

        return [
            "attachment" => [
                "type" => "template",
                "payload" => [
                    "template_type" => "button",
                    "text" => substr(strip_tags($message->message), 0, 640),
                    "buttons" => $buttons
                ]
            ]
        ];
    }

    public function buildEscalationMessage()
    {
        $buttons = array();
        $escalateOptions = [
            [
                "label" => 'yes',
                "escalate" => true
            ],
            [
                "label" => 'no',
                "escalate" => false
            ],
        ];
        foreach ($escalateOptions as $option) {
            $buttons[] = array(
                'content_type' 	=> 'text',
                'title' 		=> $this->langManager->translate($option['label']),
                'payload' 		=> json_encode([
                    'escalateOption' => $option['escalate'],
                ], true)
            );
        }
        return [
            'text' => $this->langManager->translate('ask_to_escalate'),
            'quick_replies' => $buttons
        ];
    }
}
