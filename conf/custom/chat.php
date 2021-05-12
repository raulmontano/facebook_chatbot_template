<?php

// Inbenta Hyperchat configuration
return array(
    'chat' => array(
        'enabled' => true,
        'version' => '1',
        'appId' => '',
        'secret' => '',
        'roomId' => 3,             // Numeric value, no string (without quotes)
        'lang' => 'es',
        'source' => 3,             // Numeric value, no string (without quotes)
                'importBotHistory' => true, //mostrar historial previo del chat
        'guestName' => '',
        'guestContact' => '',
        'server' => 'https://hyperchat-us.inbenta.chat',    // Your HyperChat server URL (ask your contact person at Inbenta)
        'server_port' => 443,
        'queue' => [
            'active' => true
        ]
    ),
    'triesBeforeEscalation' => 2,
    'negativeRatingsBeforeEscalation' => 2
);
