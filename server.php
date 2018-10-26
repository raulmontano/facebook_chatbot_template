<?php

include "vendor/autoload.php";

use Inbenta\FacebookConnector\FacebookConnector;

//Instance new FacebookConnector
$appPath=__DIR__.'/';
$app = new FacebookConnector($appPath);

//Handle the incoming request
$app->handleRequest();
