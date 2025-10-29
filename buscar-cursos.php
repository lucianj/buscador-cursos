<?php

require "vendor/autoload.php";

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

$client = new Client();
$res = $client->request('GET', 'https://www.alura.com.br/carreiras/desenvolvimento-backend-php/');


$html = $res->getBody();
//var_dump($html);

//echo $html;

$crawler = new Crawler($html);

$cursos = $crawler->filter('h5.course-card__title');
//var_dump($cursos);

//$cursos = $crawler->filter('span.course-card__title');


foreach ($cursos as $curso) {
    echo $curso->nodeValue . PHP_EOL;
}