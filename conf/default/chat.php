<?php

// Inbenta Hyperchat configuration
return array(
    'chat' => array(
    	'enabled' => false,
        'version' => '1',
        'appId' => '',
        'secret' => '',
        'roomId' => 1,             // Numeric value, no string (without quotes)
        'lang' => '',
        'source' => 3,             // Numeric value, no string (without quotes)
        'guestName' => '',
        'guestContact' => '',
        'region' => 'eu'            // eu or us
    ),
    'triesBeforeEscalation' => 0,
    'negativeRatingsBeforeEscalation' => 0
);
