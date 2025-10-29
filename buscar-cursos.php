<?php

require "vendor/autoload.php";

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

$client = new Client();
$res = $client->request('GET', 'https://www.alura.com.br/carreiras/desenvolvimento-backend-php/');

$html = $res->getBody();
//echo $html;

$crawler = new Crawler();

$crawler->addHtmlContent($html);

$cursos = $crawler->filter('span.course-card__title');

//var_dump($cursos);

foreach ($cursos as $curso) {
    echo $curso->textContent . PHP_EOL;
}