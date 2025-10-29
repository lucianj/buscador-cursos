<?php

use GuzzleHttp\Client;

$client = new Client();
$request = $client->request('GET', 'https://www.alura.com.br/carreiras/desenvolvimento-backend-php#mapa-da-carreira');

$html = $resposta->getBody();

