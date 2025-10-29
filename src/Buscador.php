<?php

namespace Alura\BuscadorDeCursos;

use GuzzleHttp\ClientInterface;
use Symfony\Component\DomCrawler\Crawler;

class Buscador
{
    /** 
     * @var ClientInterface 
     */
    private $httpClient;

    /** 
     * @var Crawler 
     */
    private $crawler;

    public function __construct(ClientInterface $httpClient, Crawler $crawler)
    {
        $this->httpClient = $httpClient;
        $this->crawler = $crawler;
    }

    public function buscar(string $url): array
    {
        $res = $this->httpClient
            ->request('GET', $url);
        $html = $res->getBody();
        $this->crawler->addHtmlContent($html);

        $elementosCursos = $this->crawler->filter('h5.course-card__title');

        $cursos = [];
        foreach($elementosCursos as $elemento){
            $cursos[] = $elemento->textContent;
        }
        return $cursos;
    }
}