<?php
namespace Inbenta\FacebookConnector\ExternalDigester;
use Inbenta\ChatbotConnector\ExternalDigester\Channels\DigesterInterface;

class ButtonsDigester
{
    /**
     * Build a persistent buttons-message with the given text and options data
     * @param  string $message  Text message to appear before the buttons
     * @param  array  $options  Array with the buttons data key-pairs ('title' and 'payload')
     * @return array            Persistent Facebook buttons template
     */
    public static function buildPersistentButtons(string $message, array $options)
    {
        $buttons = self::buildButtons($options, true);
        return [
            'attachment' => [
                'type' => 'template',
                'payload' => [
                    'template_type' => 'button',
                    'text' => self::formatTextMessage($message),
                    'buttons' => $buttons
                ]
            ]
        ];
    }

    /**
     * Build a temporary buttons-message with the given text and options data
     * @param  string $message  Text message to appear before the buttons
     * @param  array  $options  Array with the buttons data key-pairs ('title' and 'payload')
     * @return array            Temporary Facebook buttons template
     */
    public static function buildNonPersistentButtons(string $message, array $options)
    {
        $buttons = self::buildButtons($options, false);
        return [
            'text' => self::formatTextMessage($message),
            'quick_replies' => $buttons
        ];
    }

    /**
     * Method to format all responses text the same way
     * @param  string $message Text message to be formatted
     * @return string          Text formatted        
     */
    protected static function formatTextMessage(string $message){
        return strip_tags($message);
    }

    /**
     * Generic method to build button messages with payload
     * @param  array  $options    Array with the buttons data: 'title', 'payload'
     * @param  bool   $persistent If buttons are persistent or single-use
     * @return array              Buttons ready to be injected in a buttons template
     */
    protected static function buildButtons(array $options, bool $persistent)
    {
        $msgTypeName = $persistent ? 'type' : 'content_type';
        $msgTypeVal = $persistent ? 'postback' : 'text';

        $buttons = [];
        foreach ($options as $option) {
            $buttons[] = [
                $msgTypeName => $msgTypeVal,
                'title' => $option['title'],
                'payload' => json_encode($option['payload'], true)
            ];
        }
        return $buttons;
    }
}
