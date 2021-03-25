<?php

namespace Inbenta\FacebookConnector\ExternalDigester;

use DOMDocument;
use DOMNode;
use DOMText;
use \Exception;
use Inbenta\ChatbotConnector\ExternalDigester\Channels\DigesterInterface;
use Inbenta\FacebookConnector\ExternalDigester\ButtonsDigester;
use Inbenta\FacebookConnector\Helpers\Helper;

class FacebookDigester extends DigesterInterface
{

    protected $conf;
    protected $channel;
    protected $session;
    protected $langManager;
    protected $externalMessageTypes = [
        'text',
        'postback',
        'quickReply',
        'attachment',
        'sticker'
    ];
    protected $attachableFormats = [
        'image' => ['jpg', 'jpeg', 'png', 'gif'],
        'file' => ['pdf', 'xls', 'xlsx', 'doc', 'docx'],
        'video' => ['mp4', 'avi'],
        'audio' => ['mp3']
    ];

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

        $isPage      = isset($request->object) && $request->object == 'page';
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
        if ($this->session->has('options')) {
            $options = $this->session->get('options');
            $this->session->delete('options');

            if (isset($messages[0]) && isset($messages[0]->message) && isset($messages[0]->message->text)) {
                $userMessage = $messages[0]->message->text;
                $selectedEscalation = "";
                $selectedRating = "";
                $isEscalation = false;
                $isRating = false;
                foreach ($options as $option) {
                    if (isset($option['escalate'])) {
                        $isEscalation = true;
                    } else if (isset($option['isRating'])) {
                        $isRating = true;
                    }
                    if (Helper::removeAccentsToLower($userMessage) === Helper::removeAccentsToLower($this->langManager->translate($option['label']))) {
                        if ($isEscalation) {
                            $selectedEscalation = $option['escalate'];
                        } else if ($isRating) {
                            $selectedRating = $option['payload'];
                        }
                        break;
                    }
                }

                if ($isEscalation && $selectedEscalation !== "") {
                    if ($selectedEscalation === false) {
                        $output[] = ['message' => "no"];
                    } else {
                        $output[] = ['escalateOption' => $selectedEscalation];
                    }
                } else if ($isRating && $selectedRating !== "") {
                    $output[] = $selectedRating;
                }
            }
        } else if (isset($messages[0]) && isset($messages[0]->message) && isset($messages[0]->message->attachments)) {
            $output = $this->mediaFileToHyperchat($messages[0]->message->attachments);
        }
        if (count($output) === 0) {
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
        }
        return $output;
    }

    /**
     **	Formats an Inbenta Chatbot API response into a channel request
     **/
    public function digestFromApi($request, $lastUserQuestion = '')
    {
        $messages = [];
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
            if (!isset($msg->message) || $msg->message === "") continue;
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
        throw new Exception('Unknown Facebook message type');
    }

    /**
     **	Classifies the API message into one of the defined $apiMessageTypes
     **/
    protected function checkApiMessageType($message)
    {
        foreach ($this->apiMessageTypes as $type) {
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
        return isset($message->type) && $message->type == 'polarQuestion';
    }

    protected function isApiMultipleChoiceQuestion($message)
    {
        return isset($message->type) && $message->type == 'multipleChoiceQuestion';
    }

    protected function isApiExtendedContentsAnswer($message)
    {
        return isset($message->type) && $message->type == 'extendedContentsAnswer';
    }

    protected function hasTextMessage($message)
    {
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
            if ($attachment->type == 'location' && isset($attachment->title) && isset($attachment->url)) {
                $attachments[] = array('message' => $attachment->title . ": " . $attachment->url);
            } elseif (isset($attachment->payload) && isset($attachment->payload->url)) {
                $attachments[] = array('message' => $attachment->payload->url);
            }
        }
        return ['multiple_output' => $attachments];
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
        $output = [];
        $urlButtonSetting = isset($this->conf['url_buttons']['attribute_name'])
            ? $this->conf['url_buttons']['attribute_name']
            : '';

        if (isset($message->attributes->$urlButtonSetting) && !empty($message->attributes->$urlButtonSetting)) {
            // Send a button that opens an URL
            $output = $this->buildUrlButtonMessage($message, $message->attributes->$urlButtonSetting);
        } else if (isset($message->actionField) && !empty($message->actionField) && $message->actionField->fieldType !== 'default') {
            $output = $this->handleMessageWithActionField($message);
        }
        if (count($output) === 0) {
            if (!isset($message->messageList) && trim($message->message) !== "") {
                $message->messageList = [$message->message];
            } else if ((is_array($message->messageList) && count($message->messageList) == 0) || !is_array($message->messageList)) {
                $message->messageList = [""];
            }
            if (isset($message->attributes->SIDEBUBBLE_TEXT) && trim($message->attributes->SIDEBUBBLE_TEXT) !== "") {
                $countMessages = count($message->messageList);
                $message->messageList[$countMessages] = $message->attributes->SIDEBUBBLE_TEXT;
            }

            $output['multiple_output'] = [];
            foreach ($message->messageList as $messageTxt) {
                $nodesBlocks = $this->createNodesBlocks($messageTxt);
                $outputTmp = [];
                foreach ($nodesBlocks as $nodeBlock) {
                    // node are returned as array when media files have been found
                    if (is_array($nodeBlock)) {
                        if (isset($nodeBlock['src'])) {
                            //Append the media type to the answer
                            $outputTmp[] = [
                                'attachment' => [
                                    'type' => $nodeBlock['type'],
                                    'payload' => [
                                        'is_reusable' => false,
                                        'url' => $nodeBlock['src']
                                    ]
                                ]
                            ];
                        }
                    } else {
                        $text = $this->cleanHtml($nodeBlock);
                        if (trim($text) !== "") {
                            $outputTmp[] = $text;
                        }
                    }
                }
                foreach ($outputTmp as $index => $element) {
                    if (is_array($element)) {
                        $output['multiple_output'][] = $element;
                    } else {
                        if ($index > 0) {
                            $countElements = count($output['multiple_output']);
                            if ($countElements > 0 && isset($output['multiple_output'][$countElements - 1]['text'])) {
                                $output['multiple_output'][$countElements - 1]['text'] .= strpos($element, "\n") === 0 ? $element : "\n" . $element;
                                continue;
                            }
                        }
                        $output['multiple_output'][]['text'] = $element;
                    }
                }
            }
            if (count($output['multiple_output']) > 0) {
                $output['multiple_output'] = $this->handleMessageWithRelatedContent($message, $output['multiple_output']);
            } else {
                $output = [];
            }
        }
        return $output;
    }

    protected function digestFromApiPolarQuestion($message, $lastUserQuestion)
    {
        $buttonOptions = array();
        foreach ($message->options as $option) {
            $buttonOptions[] = [
                'title' => $this->langManager->translate($option->label),
                'payload' => [
                    'option' => $option->value
                ]
            ];
        }
        $response = ButtonsDigester::buildNonPersistentButtons($message->message, $buttonOptions);
        return $response;
    }

    protected function digestFromApiExtendedContentsAnswer($message)
    {
        $buttonTitleSetting = isset($this->conf['button_title']) ? $this->conf['button_title'] : '';
        $message->subAnswers = array_slice($message->subAnswers, 0, 3);

        $buttonOptions = [];
        $linkButton = false;
        $this->session->set('federatedSubanswers', $message->subAnswers);
        foreach ($message->subAnswers as $index => $option) {
            if (isset($option->parameters) && isset($option->parameters->contents) && isset($option->parameters->contents->url)) {
                $buttonOptions[] = [
                    'title' => $option->attributes->title,
                    "type" => "web_url",
                    "url" => $option->parameters->contents->url->value
                ];
                $linkButton = true;
            } else {
                $buttonOptions[] = [
                    'title' => isset($option->attributes->$buttonTitleSetting)
                        ? $option->attributes->$buttonTitleSetting
                        : $option->attributes->title,
                    'payload' => [
                        "extendedContentAnswer" => $index
                    ]
                ];
            }
        }
        $response = [];
        if ($linkButton) {
            $response = ButtonsDigester::buildLinkButtons($message->message, $buttonOptions);
        } else {
            $response = array_merge($response, ButtonsDigester::buildPersistentButtons($message->message, $buttonOptions));
        }
        return $response;
    }

    protected function digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion)
    {
        $isMultiple = isset($message->flags) && in_array('multiple-options', $message->flags, true);
        $buttonTitleSetting = isset($this->conf['button_title']) ? $this->conf['button_title'] : '';
        $message->options = array_slice($message->options, 0, 3);

        $isDirectCall = true;
        $buttonOptions = array();
        foreach ($message->options as $option) {
            if (!isset($option->revisitableLink) || !$option->revisitableLink) {
                $opType = 'option';
                $isDirectCall = false;
                $opVal = $option->value;
            } else {
                $opType = 'directCall';
                $opVal = $option->revisitableLink;
            }

            $buttonOptions[] = [
                'title' => $isMultiple && isset($option->attributes->$buttonTitleSetting)
                    ? $option->attributes->$buttonTitleSetting
                    : $option->label,
                'payload' => [
                    $opType => $opVal
                ]
            ];
        }

        $response = $isDirectCall
            ? ButtonsDigester::buildPersistentButtons($message->message, $buttonOptions)
            : ButtonsDigester::buildNonPersistentButtons($message->message, $buttonOptions);
        return $response;
    }


    /********************** MISC **********************/

    public function buildContentRatingsMessage($ratingOptions, $rateCode)
    {
        $buttonOptions = array();
        foreach ($ratingOptions as $index => $option) {
            $buttonOptions[$index] = array(
                'title'  => $this->langManager->translate($option['label']),
                'payload' => [
                    'askRatingComment' => isset($option['comment']) && $option['comment'],
                    'isNegativeRating' => isset($option['isNegative']) && $option['isNegative'],
                    'ratingData' =>    [
                        'type' => 'rate',
                        'data' => array(
                            'code'    => $rateCode,
                            'value'   => $option['id'],
                            'comment' => null
                        )
                    ]
                ]
            );
            $ratingOptions[$index]["payload"] = $buttonOptions[$index]["payload"];
            $ratingOptions[$index]["isRating"] = true;
        }

        $this->session->set('options', $ratingOptions);
        $message = $this->langManager->translate('rate_content_intro');
        $response = ButtonsDigester::buildNonPersistentButtons($message, $buttonOptions);

        return $response;
    }

    /**
     * Splits a message that contains an <img> tag into text/image/text and displays them in Facebook
     * Overwritten, functionality replaced with "handleDOMImages()"
     */
    protected function handleMessageWithImages($message)
    {
        return [];
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

        $buttons = [];
        foreach ($urlButton as $button) {
            // If any of the urlButtons has any invalid/missing url or title, abort and send a simple text message
            if (!isset($button->$buttonURLProp) || !isset($button->$buttonTitleProp) || empty($button->$buttonURLProp) || empty($button->$buttonTitleProp)) {
                return ['text' => $this->cleanHtml($message->message)];
            }
            $buttons[] = [
                'type' => 'web_url',
                'url' => $button->$buttonURLProp,
                'title' => $button->$buttonTitleProp,
                'webview_height_ratio' => 'full'
            ];
        }

        return [
            'attachment' => [
                'type' => 'template',
                'payload' => [
                    'template_type' => 'button',
                    'text' => substr(strip_tags($message->message), 0, 640),
                    'buttons' => $buttons
                ]
            ]
        ];
    }

    /**
     * Build the message and options to escalate
     * @return array
     */
    public function buildEscalationMessage()
    {
        $buttonOptions = [];
        $escalateOptions = [
            [
                'label' => 'yes',
                'escalate' => true
            ],
            [
                'label' => 'no',
                'escalate' => false
            ]
        ];
        foreach ($escalateOptions as $option) {
            $buttonOptions[] = array(
                'title' => $this->langManager->translate($option['label']),
                'payload' => [
                    'escalateOption' => $option['escalate'],
                ]
            );
        }
        $this->session->set('options', $escalateOptions);

        $message = $this->langManager->translate('ask_to_escalate');
        $response = ButtonsDigester::buildNonPersistentButtons($message, $buttonOptions);
        return $response;
    }

    /**
     * Check if the current {@link DOMNode} children has an image node
     *
     * @param DOMNode $element
     * @return bool
     */
    public function domElementHasImage($element): bool
    {
        if (!$element instanceof DOMText) {
            $images = $element->getElementsByTagName('img');
            return $images->length > 0 ? true : false;
        }
        return false;
    }

    /**
     * Return an HTML {@link string} form a {@link DOMNode} element
     *
     * @param DOMNode $element
     * @return string
     */
    public function getElementHTML($element)
    {
        $tmp = new \DOMDocument();
        $tmp->appendChild($tmp->importNode($element, true));
        return $tmp->saveHTML();
    }

    /**
     * This check {@link DOMNode::$childNodes} and search for images, then return an array
     * containing the `alt` and `src` attributes or the {@link DOMNode} HTML if not an image.
     *
     * @param DOMNode $element Given {@link DOMNode} element
     * @return array
     */
    public function handleDOMImages(DOMNode $element): array
    {
        $elements = [];
        foreach ($element->childNodes as $childNode) {
            /** @type DOMNode $childNode */
            if ($childNode->nodeName === 'img') {
                $elements[] = [
                    'type' => 'image',
                    'src' => $childNode->getAttribute('src')
                ];
            } else {
                $elements[] = $this->getElementHTML($childNode);
            }
        }
        return $elements;
    }

    /**
     * This check {@link DOMNode::$childNodes} and search for iframe, then return an array
     * containing the link of the src of the iframe
     */
    public function handleDOMIframe(DOMNode $element): array
    {
        $elements = [];
        foreach ($element->childNodes as $childNode) {
            /** @type DOMNode $childNode */
            if ($childNode->nodeName === 'iframe') {
                $source = $childNode->getAttribute('src');
                if ($source) {
                    $urlElements = explode(".", $source);
                    $fileFormat = $urlElements[count($urlElements) - 1];
                    $mediaElement = false;
                    foreach ($this->attachableFormats as $type => $formats) {
                        if (in_array($fileFormat, $formats)) {
                            $mediaElement = true;
                            $elements[] = [
                                'type' => $type,
                                'src' => $source
                            ];
                            break;
                        }
                    }
                    if (!$mediaElement) {
                        $elements[] = $source;
                    }
                } else {
                    $elements[] = $this->getElementHTML($childNode);
                }
            } else {
                $elements[] = $this->getElementHTML($childNode);
            }
        }
        if (count($elements) === 0 && isset($element->nodeName)) {
            if ($element->nodeName === 'iframe') {
                $source = method_exists($element, "getAttribute") ? $element->getAttribute('src') : "";
                if ($source !== "") {
                    $urlElements = explode(".", $source);
                    $fileFormat = $urlElements[count($urlElements) - 1];
                    $mediaElement = false;
                    foreach ($this->attachableFormats as $type => $formats) {
                        if (in_array($fileFormat, $formats)) {
                            $mediaElement = true;
                            $elements[] = [
                                'type' => $type,
                                'src' => $source
                            ];
                            break;
                        }
                    }
                    if (!$mediaElement) {
                        $elements[] = $source;
                    }
                }
            }
        }
        return $elements;
    }


    /**
     * This check {@link DOMNode::$childNodes} and search for "a" tag, then return an array
     * containing the link of the href of all the "a" tags
     */
    public function handleDOMLink(DOMNode $element): array
    {
        $elements = [];
        foreach ($element->childNodes as $childNode) {
            if ($childNode->nodeName === 'a') {
                $href = $childNode->getAttribute('href');
                $childNode->textContent;
                $elements[] = $childNode->textContent . ": " . $href;
            } else {
                $elements[] = $childNode->textContent;
            }
        }
        return $elements;
    }


    /**
     * This check {@link DOMNode::$childNodes} and search for "li" tag, then return an array
     * containing the text with a dash of all list elements
     */
    public function handleDOMList(DOMNode $element): array
    {
        $elements = [];
        foreach ($element->childNodes as $childNode) {
            if ($childNode->nodeName === 'li') {
                $text = strpos($childNode->textContent, "\n") === 0 ? substr($childNode->textContent, 2) : $childNode->textContent;
                $elements[] = "- " . $text;
            }
        }
        return $elements;
    }

    /**
     * This create an array of `string` & `array` using {@link DOMDocument::loadHTML()} from the HTML String
     * passed in the parameter.
     *
     * @param string $html HTML String
     * @param array $defaultsNodesBlocks Default nodes
     * @return array $nodesBlocks|[]
     */
    public function createNodesBlocks($html, $defaultsNodesBlocks = [])
    {
        $nodesBlocks = $defaultsNodesBlocks;
        try {
            $dom = new DOMDocument();
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

            //@var DOMNode $body 
            $body = $dom->getElementsByTagName('body')[0];

            if (isset($body->childNodes)) {
                foreach ($body->childNodes as $childNode) {
                    //@type DOMNode $childNode
                    if ($this->domElementHasImage($childNode)) {
                        $nodesBlocks = array_merge($nodesBlocks, $this->handleDOMImages($childNode));
                    } else {
                        if (strpos($this->getElementHTML($childNode), '<iframe') !== false) {
                            $nodesBlocks = array_merge($nodesBlocks, $this->handleDOMIframe($childNode));
                        } else if (strpos($this->getElementHTML($childNode), '<a') !== false) {
                            $nodesBlocks = array_merge($nodesBlocks, $this->handleDOMLink($childNode));
                        } else if (strpos($this->getElementHTML($childNode), '<li') !== false) {
                            $nodesBlocks = array_merge($nodesBlocks, $this->handleDOMList($childNode));
                        } else {
                            $nodesBlocks[] = $childNode->textContent;
                        }
                    }
                }
            }
            return $nodesBlocks;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Validate if the message has action fields
     * @param object $message
     * @param array $output
     * @return array $output
     */
    protected function handleMessageWithActionField(object $message)
    {
        $output = [];
        if (isset($message->actionField) && !empty($message->actionField)) {
            if ($message->actionField->fieldType === 'list') {
                $output = $this->handleMessageWithListValues($message->message, $message->actionField->listValues);
            } else if ($message->actionField->fieldType === 'datePicker') {
                $output['text'] = $this->cleanHtml($message->message . " (date format: mm/dd/YYYY)");
            }
        }
        return $output;
    }

    /**
     * Set the options for message with list values
     * @param string $message
     * @param object $listValues
     * @return array $output
     */
    protected function handleMessageWithListValues(string $message, object $listValues)
    {
        $output = [];
        $buttonOptionList = [];
        foreach ($listValues->values as $index => $option) {
            $buttonOptionList[] = [
                'title' => $option->label[0],
                'payload' => [
                    'message' => $option->label[0]
                ]
            ];
            if ($index == 2) break;
        }
        if (count($buttonOptionList) > 0) {
            $output = ButtonsDigester::buildPersistentButtons($message, $buttonOptionList);
        }
        return $output;
    }

    /**
     * Validate if the message has related content and put like an option list
     * @param object $message
     * @param array $output
     * @return array $output
     */
    protected function handleMessageWithRelatedContent(object $message, array $output)
    {
        if (isset($message->parameters->contents->related->relatedContents) && !empty($message->parameters->contents->related->relatedContents)) {
            $buttonRelatedContent = [];
            foreach ($message->parameters->contents->related->relatedContents as $index => $relatedContent) {
                $buttonRelatedContent[] = [
                    'title' => $relatedContent->title,
                    'payload' => [
                        'message' => $relatedContent->title
                    ]
                ];
                if ($index == 2) break;
            }
            if (count($buttonRelatedContent) > 0) {
                $title = $message->parameters->contents->related->relatedTitle;
                $output[] = ButtonsDigester::buildPersistentButtons($title, $buttonRelatedContent);
            }
        }
        return $output;
    }

    /**
     * Clean html tags from message
     * @param string $message
     * @return string $message
     */
    public function cleanHtml(string $message)
    {
        $message = str_replace(["<br/>", "<br>", "<br />"], "\n", $message);
        $message = str_replace(["\t", "\n\n"], '', strip_tags($message));
        return $message;
    }


    /**
     * Check if Hyperchat is running and if the attached file is correct
     * @param array $attachments
     * @return array $output
     */
    protected function mediaFileToHyperchat(array $attachments)
    {
        $output = [];
        if ($this->session->get('chatOnGoing', false)) {
            foreach ($attachments as $attachment) {
                if (isset($attachment->payload) && isset($attachment->payload->url)) {
                    $mediaFile = $this->getMediaFile($attachment->payload->url, $attachment->type);
                    if ($mediaFile !== "") {
                        $output[] = ['media' => $mediaFile];
                    }
                }
            }
        }
        return $output;
    }

    /**
     * Get the media file from Facebook response, 
     * save file into temporal directory to sent to Hyperchat
     * @param string $fileUrl
     */
    protected function getMediaFile(string $fileUrl, string $type)
    {
        $formatsToSearch = isset($this->attachableFormats[$type]) ? $this->attachableFormats[$type] : [];
        foreach ($formatsToSearch as $format) {
            if (strpos($fileUrl, "." . $format) !== false) {
                $uniqueName = str_replace(" ", "", microtime(false));
                $uniqueName = str_replace("0.", "", $uniqueName);

                $fileName = sys_get_temp_dir() . "/file" . $uniqueName . "." . $format;
                $tmpFile = fopen($fileName, "w") or die;
                fwrite($tmpFile, file_get_contents($fileUrl));
                $fileRaw = fopen($fileName, 'r');
                @unlink($fileName);

                return $fileRaw;
            }
        }
        return "";
    }
}
